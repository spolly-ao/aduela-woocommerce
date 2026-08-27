<?php
/**
 * O que o Aduela nos vem dizer.
 *
 * # O sentido de descida, e porque é que ele não existia
 *
 * Até aqui tudo fluía numa direção: o catálogo descia, as encomendas subiam.
 * Quando o lojista mudava o estado no Aduela, esta loja não ficava a saber, e
 * passava a dizer ao comprador uma coisa diferente da que o Aduela dizia. É aí
 * que ele telefona a perguntar qual das duas é verdade.
 *
 * # Como é que o Aduela prova que é ele
 *
 * Com um segredo **diferente** da chave do canal, e é de propósito. A chave prova
 * ao Aduela que quem fala é este plugin; este prova a este plugin que quem fala é
 * o Aduela. Reutilizar a chave para as duas coisas era usar, para autenticar
 * quem a guarda cifrada, um segredo que nós guardamos em claro.
 *
 * O segredo pede-se ao Aduela, autenticando com a chave, e ele responde uma vez.
 * Fica gravado aqui e comparado em cada aviso.
 *
 * # E não volta a subir
 *
 * A encomenda é marcada antes de se lhe mexer no estado, e o gancho que manda os
 * estados para cima sai sem dizer nada quando vê essa marca. Sem isso os dois
 * lados entravam num ciclo: cada um a avisar o outro do que o outro lhe acabou
 * de dizer.
 */

defined( 'ABSPATH' ) || exit;

class Aduela_Retorno {

	/** Onde se guarda o segredo com que o Aduela se identifica. */
	const OPCAO_SEGREDO = 'aduela_wc_segredo_de_retorno';
	/** E o endereço que se registou, para se saber se mudou. */
	const OPCAO_URL = 'aduela_wc_url_de_retorno';

	/** O cabeçalho onde o segredo viaja. */
	const CABECALHO = 'X-Aduela-Retorno';

	public static function registar() {
		add_action( 'rest_api_init', array( __CLASS__, 'rotas' ) );
	}

	public static function rotas() {
		register_rest_route(
			'aduela/v1',
			'/encomenda/estado',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'ao_receber' ),
				// **A permissão é o segredo, e não uma sessão.** Quem bate aqui é
				// um servidor, e não uma pessoa com sessão iniciada no WordPress.
				'permission_callback' => array( __CLASS__, 'e_mesmo_o_aduela' ),
			)
		);
	}

	/**
	 * Confirma que quem bate é o Aduela desta loja.
	 *
	 * **Comparação em tempo constante.** Um `===` entre segredos deixa medir
	 * quanto tempo demorou a falhar, e com pedidos suficientes isso diz onde é que
	 * eles passaram a divergir. O `hash_equals` existe para isto.
	 */
	public static function e_mesmo_o_aduela( $pedido ) {
		$guardado = (string) get_option( self::OPCAO_SEGREDO, '' );
		if ( '' === $guardado ) {
			return false;
		}

		$veio = (string) $pedido->get_header( self::CABECALHO );

		return '' !== $veio && hash_equals( $guardado, $veio );
	}

	/**
	 * O Aduela diz que a encomenda mudou de estado, e a loja acompanha.
	 *
	 * # A tradução vive aqui, e não do outro lado
	 *
	 * O Aduela manda o estado dele (`aceite`, `pronta`, `entregue`, `recusada`) e
	 * é este plugin que sabe o que isso é num WooCommerce. **É o sítio certo:** o
	 * dia em que houver um Shopify, o vocabulário dele é problema do plugin dele,
	 * e o Aduela continua a falar como fala.
	 */
	public static function ao_receber( $pedido ) {
		$corpo = $pedido->get_json_params();

		$referencia = isset( $corpo['referencia'] ) ? (string) $corpo['referencia'] : '';
		$estado     = isset( $corpo['estado'] ) ? (string) $corpo['estado'] : '';
		$motivo     = isset( $corpo['motivo'] ) ? (string) $corpo['motivo'] : '';

		if ( '' === $referencia || '' === $estado ) {
			return new WP_Error( 'aduela_incompleto', __( 'Falta a referência ou o estado.', 'aduela-woocommerce' ), array( 'status' => 400 ) );
		}

		$encomenda = self::pela_referencia( $referencia );
		if ( ! $encomenda ) {
			return new WP_Error( 'aduela_nao_encontrada', __( 'Não conheço essa encomenda.', 'aduela-woocommerce' ), array( 'status' => 404 ) );
		}

		$novo = self::traduzir( $estado );

		if ( '' === $novo ) {
			// **Um estado que não tem par aqui não é erro.** O Aduela tem estados
			// que o WooCommerce não tem, e responder com erro punha o Aduela a
			// escrever "a loja recusou" no registo por causa de nada.
			$encomenda->add_order_note(
				sprintf(
					/* translators: %s: estado no Aduela */
					__( 'Aduela: passou a "%s".', 'aduela-woocommerce' ),
					$estado
				)
			);
			$encomenda->save();

			return rest_ensure_response( array( 'ok' => true, 'aplicado' => false ) );
		}

		if ( $encomenda->get_status() === $novo ) {
			return rest_ensure_response( array( 'ok' => true, 'aplicado' => false ) );
		}

		/*
		 * **Uma encomenda não anda para trás.** Cartão `36.32`.
		 *
		 * Apanhado a testar, e era um defeito a sério: o Aduela tem `aceite` e
		 * `pronta` como dois passos, e o WooCommerce chama `processing` aos dois.
		 * Marcar "pronta" no Aduela uma encomenda que aqui já estava `completed`
		 * puxava-a de volta para `processing`, e o comprador via a encomenda dele
		 * a desandar.
		 *
		 * **A regra é o sentido, e não a igualdade:** aplica-se o que avança, e
		 * ignora-se o que recua. O cancelamento é a exceção, e é legítima: uma
		 * encomenda pode ser anulada depois de entregue, e o Aduela permite-o pela
		 * mesma razão.
		 */
		if ( ! self::avanca( $encomenda->get_status(), $novo ) ) {
			$encomenda->add_order_note(
				sprintf(
					/* translators: 1: estado no Aduela, 2: estado nesta loja */
					__( 'Aduela: passou a "%1$s". Aqui fica em "%2$s", que já vai à frente.', 'aduela-woocommerce' ),
					$estado,
					$encomenda->get_status()
				)
			);
			$encomenda->save();

			return rest_ensure_response( array( 'ok' => true, 'aplicado' => false, 'motivo' => 'recuaria' ) );
		}

		/*
		 * **A marca antes da mudança, e não depois.** Cartão `36.32`.
		 *
		 * O gancho do estado dispara dentro do `update_status`, e não depois: pôr
		 * a marca a seguir era pô-la tarde de mais, e o plugin ia dizer ao Aduela
		 * o que o Aduela lhe acabou de dizer.
		 */
		$encomenda->update_meta_data( Aduela_Encomendas::META_VEIO_DO_ADUELA, 1 );
		$encomenda->save();

		$nota = '' !== $motivo
			? sprintf(
				/* translators: 1: estado no Aduela, 2: motivo */
				__( 'Aduela: passou a "%1$s". %2$s', 'aduela-woocommerce' ),
				$estado,
				$motivo
			)
			: sprintf(
				/* translators: %s: estado no Aduela */
				__( 'Aduela: passou a "%s".', 'aduela-woocommerce' ),
				$estado
			);

		$encomenda->update_status( $novo, $nota );

		$encomenda->delete_meta_data( Aduela_Encomendas::META_VEIO_DO_ADUELA );
		$encomenda->save();

		return rest_ensure_response( array( 'ok' => true, 'aplicado' => true, 'estado' => $novo ) );
	}

	/**
	 * O estado do Aduela, dito em WooCommerce.
	 *
	 * **Nem todos têm par, e devolve-se vazio nesses.** O `recebida` do Aduela é
	 * uma encomenda por tratar, que aqui já é o `processing` em que ela está: pô-la
	 * lá outra vez não muda nada e enche o histórico.
	 */
	private static function traduzir( $estado ) {
		switch ( strtolower( trim( $estado ) ) ) {
			case 'aceite':
			case 'pronta':
				// **As duas dão `processing`**, e é o que o WooCommerce tem: para
				// ele, tudo o que está entre pago e entregue é "a processar". O
				// detalhe fica na nota, que é onde o lojista o lê.
				return 'processing';
			case 'entregue':
				return 'completed';
			case 'recusada':
				return 'cancelled';
			default:
				return '';
		}
	}

	/**
	 * Diz se ir de um estado para o outro é avançar.
	 *
	 * **O cancelamento avança sempre.** Não é um passo na linha: é sair dela, e
	 * pode acontecer a partir de qualquer ponto. Uma encomenda entregue que se
	 * anula é uma coisa que existe, e o Aduela também a permite.
	 */
	private static function avanca( $atual, $novo ) {
		if ( in_array( $novo, array( 'cancelled', 'refunded' ), true ) ) {
			return true;
		}

		return self::quao_longe( $novo ) > self::quao_longe( $atual );
	}

	/**
	 * Quão longe na vida da encomenda está este estado.
	 *
	 * Os que ficam a zero são os que ainda não começaram, e os terminais
	 * negativos (`cancelled`, `refunded`, `failed`) não entram nesta conta: quem
	 * decide sobre eles é o `avanca`.
	 */
	private static function quao_longe( $estado ) {
		switch ( $estado ) {
			case 'processing':
				return 1;
			case 'completed':
				return 2;
			default:
				return 0;
		}
	}

	/** Encontra a encomenda pelo número que o Aduela conhece. */
	private static function pela_referencia( $referencia ) {
		// O Aduela manda a referência com o prefixo do canal (`WOO-1042`), que é
		// como ele a guarda. O número desta loja é o que vem depois.
		$numero = preg_replace( '/^[A-Z]+-/', '', trim( $referencia ) );

		$encomenda = wc_get_order( (int) $numero );

		return $encomenda ? $encomenda : null;
	}

	/**
	 * Diz ao Aduela por onde queremos ser avisados, e guarda o segredo dele.
	 *
	 * # Corre na sincronização, e não uma vez só
	 *
	 * Porque o endereço muda: uma loja que mude de domínio, que saia de um
	 * subdiretório, ou que passe a `https` deixava de receber avisos em silêncio.
	 * A passagem periódica confirma o que está registado e só fala com o Aduela
	 * quando ele mudou, que é quase nunca.
	 */
	public static function garantir_registo( $cliente ) {
		if ( ! $cliente->configurado() ) {
			return;
		}

		$nosso = rest_url( 'aduela/v1/encomenda/estado' );

		$registado = (string) get_option( self::OPCAO_URL, '' );
		$segredo   = (string) get_option( self::OPCAO_SEGREDO, '' );

		if ( $registado === $nosso && '' !== $segredo ) {
			return;
		}

		$resposta = $cliente->enviar( '/api/v1/integracoes/canais/retorno', array( 'url' => $nosso ) );

		if ( ! $resposta['ok'] ) {
			// **Não é fatal, e não se repete em ciclo.** O que se perde é o
			// espelho; o catálogo e as encomendas continuam a andar. A passagem
			// seguinte tenta outra vez, daqui a quinze minutos.
			return;
		}

		$novo = isset( $resposta['dados']['segredo'] ) ? (string) $resposta['dados']['segredo'] : '';

		if ( '' === $novo ) {
			return;
		}

		update_option( self::OPCAO_SEGREDO, $novo, false );
		update_option( self::OPCAO_URL, $nosso, false );
	}

	/** O que o ecrã de definições mostra sobre isto. */
	public static function estado() {
		return array(
			'url'     => (string) get_option( self::OPCAO_URL, '' ),
			'ligado'  => '' !== (string) get_option( self::OPCAO_SEGREDO, '' ),
		);
	}
}
