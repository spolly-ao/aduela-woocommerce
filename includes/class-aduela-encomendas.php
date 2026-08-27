<?php
/**
 * As encomendas, que sobem para o Aduela.
 *
 * # Duas oportunidades, e é de propósito
 *
 * A encomenda tenta subir **no momento em que fica paga**, pelo gancho do
 * WooCommerce, e é o caminho normal: o lojista vê a venda no Aduela em segundos.
 *
 * Quando isso falha (o Aduela em baixo, a rede a cair), ela fica marcada e o
 * `wp-cron` volta a tentar de quinze em quinze minutos. **Sem a segunda
 * oportunidade, uma encomenda perdida ficava perdida** até alguém reparar, e
 * quem repara é sempre o cliente que não recebeu a fatura.
 *
 * # A fila, e porque é que não é uma consulta
 *
 * **As que faltam subir vivem numa fila nossa, e não numa consulta ao
 * WooCommerce.** A primeira versão perguntava "dá-me as encomendas pagas sem a
 * marca do Aduela", com um `meta_query`, e isso parte: desde a versão 9.2 o
 * WooCommerce guarda as encomendas numa tabela própria (o HPOS), e nessa
 * arrumação o `meta_query` **não é suportado**. Numa loja com HPOS ligado a
 * consulta não devolve o que se pediu, e o aviso só aparece a quem tiver o
 * `WP_DEBUG` ligado: as encomendas deixavam de subir em silêncio.
 *
 * A fila é uma opção com identificadores, e funciona nas duas arrumações. Custa
 * uma escrita por encomenda que falhe, e não custa nada às que passam à primeira.
 *
 * # Repetir é seguro, e é o Aduela que o garante
 *
 * A referência da encomenda é a chave de idempotência: mandar a mesma duas vezes
 * dá a mesma venda, e o Aduela responde `200` em vez de `201` para o dizer. É por
 * isso que se pode repetir sem contar tentativas nem ter medo.
 */

defined( 'ABSPATH' ) || exit;

class Aduela_Encomendas {

	/** A marca que diz que esta encomenda já subiu, e com que venda. */
	const META_VENDA = '_aduela_venda_id';
	/** A marca do que falhou, com o motivo. */
	const META_ERRO = '_aduela_erro';
	/**
	 * Quando ela subiu para o Aduela.
	 *
	 * **Separada da venda**, desde o cartão `36.32`: uma encomenda à cobrança
	 * sobe sem venda nenhuma, e sem esta marca parecia nunca ter subido.
	 */
	const META_SUBIU = '_aduela_subiu';
	/**
	 * A marca de que esta mudança veio do Aduela, e não daqui.
	 *
	 * Existe para cortar o ciclo: sem ela, o Aduela dizia "aceite", o plugin
	 * gravava, o gancho do estado disparava, e o plugin dizia ao Aduela "aceite",
	 * que o gravava e voltava a avisar. Cartão `36.32`.
	 */
	const META_VEIO_DO_ADUELA = '_aduela_a_aplicar';
	/** A fila das que ficaram por subir, por identificador. */
	const FILA = 'aduela_wc_fila';
	/**
	 * Quantas se tenta por passagem do cron.
	 *
	 * Uma loja que esteve uma semana em baixo tem centenas por subir, e mandá-las
	 * todas num pedido de cron dá um tempo-limite do PHP a meio. As que passaram
	 * saem da fila; as outras vêm na passagem seguinte.
	 */
	const POR_PASSAGEM = 20;

	public static function registar() {
		/*
		 * **A encomenda sobe assim que é feita.** Cartão `36.32`.
		 *
		 * Até aqui subia no `payment_complete` ou quando alguém a marcasse como
		 * concluída, e a razão estava escrita: *"uma encomenda por pagar não é
		 * uma venda, e emitir fatura-recibo de uma coisa que ninguém pagou é
		 * emitir um documento errado"*.
		 *
		 * **Essa razão morreu com o cartão `36.30`.** A encomenda deixou de ir
		 * direta ao Comercial e passa a cair na Loja online: sem pagamento entra
		 * *por tratar*, sem documento nenhum, e a fatura só sai quando o dinheiro
		 * confirmar ou quando o lojista a aceitar.
		 *
		 * O que a decisão antiga custava: numa loja que cobre **na entrega**, o
		 * `payment_complete` nunca dispara. A encomenda ficava em `processing` e
		 * o Aduela não sabia dela até alguém a fechar à mão. Foi o que aconteceu
		 * ao dono do produto, e o sintoma dele foi "não chegou em momento
		 * nenhum".
		 *
		 * **Os dois ganchos de criação, e não um.** O `checkout_order_processed`
		 * é o checkout clássico; o `store_api_checkout_order_processed` é o
		 * checkout em blocos, que é o que uma loja nova traz por defeito. Ter só
		 * o primeiro dava exatamente a mesma avaria numa loja moderna.
		 */
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'ao_nascer' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'ao_nascer' ) );

		/*
		 * **E os do pagamento ficam, como rede.**
		 *
		 * Depois do gancho do estado (mais abaixo), estes já não são o caminho
		 * normal: quem diz ao Aduela que o dinheiro entrou é a mudança de estado,
		 * e ela chega primeiro. Ficam para a encomenda que **não conseguiu subir**
		 * quando foi feita, com o Aduela em baixo: aí não há estado nenhum lá para
		 * mudar, e é por aqui que ela entra, já com o pagamento feito.
		 *
		 * Uma que já tenha subido sai na primeira linha do `enviar`, sem pedido
		 * nenhum: repeti-la aqui dava dois pedidos onde basta um, e uma linha
		 * `repetida` no registo do canal a dizer que não aconteceu nada.
		 */
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'ao_pagar' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'ao_pagar' ) );

		/*
		 * **Cada mudança de estado sobe.** Cartão `36.32`.
		 *
		 * Uma encomenda vive em dois sítios ao mesmo tempo: aqui, onde o comprador
		 * a fez, e na Loja online do Aduela, onde o lojista a trata. Os dois têm
		 * de dizer a mesma coisa, ou o comprador liga a perguntar qual das duas é
		 * verdade.
		 *
		 * **O Aduela ignora o que não lhe diz respeito**, e é ele que decide: o
		 * que ele aceita é o pagamento e o cancelamento, e o resto anota e deixa
		 * como está. Mandar tudo e deixá-lo escolher é melhor do que escolher
		 * aqui: a regra fica num sítio só, e é do lado que a sabe.
		 */
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'ao_mudar_de_estado' ), 10, 4 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'caixa_na_encomenda' ) );
	}

	/**
	 * O estado mudou aqui, e o Aduela tem de saber.
	 *
	 * # Só depois de ela ter subido
	 *
	 * Uma encomenda que ainda não subiu não tem lá estado nenhum para mudar, e o
	 * Aduela responderia que não a conhece. Ela sobe primeiro, pelos ganchos da
	 * criação e do pagamento, e só então isto tem a quem falar.
	 *
	 * # E não volta a descer
	 *
	 * Quando é o Aduela a mudar o estado aqui, ele marca a encomenda antes de o
	 * fazer, e esta função sai sem dizer nada. Sem isso os dois lados entravam num
	 * ciclo: cada um a avisar o outro do que o outro lhe acabou de dizer.
	 */
	public static function ao_mudar_de_estado( $id, $de, $para, $encomenda = null ) {
		$encomenda = $encomenda instanceof WC_Order ? $encomenda : wc_get_order( $id );

		if ( ! $encomenda || ! $encomenda->get_meta( self::META_SUBIU ) ) {
			return;
		}

		if ( $encomenda->get_meta( self::META_VEIO_DO_ADUELA ) ) {
			return;
		}

		$cliente = Aduela_Cliente::das_definicoes();
		if ( ! $cliente->configurado() ) {
			return;
		}

		$resposta = $cliente->enviar(
			'/api/v1/integracoes/canais/encomendas/' . rawurlencode( (string) $encomenda->get_order_number() ) . '/estado',
			array(
				'estado' => (string) $para,
				'motivo' => '',
				// **O pagamento vai à parte do estado**, e não se deduz dele. O
				// `processing` quer dizer "a tratar", e num pagamento na entrega
				// isso não é pago: quem sabe a diferença é esta loja, e é aqui que
				// se diz. Cartão `36.32`.
				'paga'   => (bool) $encomenda->get_date_paid(),
			),
			'PUT'
		);

		if ( ! $resposta['ok'] ) {
			// **Não se enfileira**, e é uma decisão. A fila existe para encomendas
			// que não subiram, e uma encomenda é uma coisa que não se pode perder;
			// um estado é uma fotografia, e a fotografia certa é a última. Repetir
			// a de há uma hora depois de ela ter mudado duas vezes era escrever
			// por cima do presente com o passado.
			$encomenda->add_order_note(
				sprintf(
					/* translators: 1: estado, 2: mensagem de erro */
					__( 'Aduela: não consegui dizer que passou a "%1$s". %2$s', 'aduela-woocommerce' ),
					$para,
					$resposta['erro']
				)
			);
			$encomenda->save();
		}
	}

	/**
	 * A encomenda acabou de ser feita.
	 *
	 * O `store_api_checkout_order_processed` passa o objeto e não o
	 * identificador, ao contrário do outro: aceita-se qualquer um dos dois.
	 */
	public static function ao_nascer( $encomenda_ou_id ) {
		$encomenda = $encomenda_ou_id instanceof WC_Order
			? $encomenda_ou_id
			: wc_get_order( $encomenda_ou_id );

		if ( ! $encomenda ) {
			return;
		}

		self::ao_pagar( $encomenda->get_id() );
	}

	/** Quantas estão à espera de subir. */
	public static function quantas_por_enviar() {
		return count( self::fila() );
	}

	/** A fila, sempre como lista de inteiros. */
	private static function fila() {
		$fila = get_option( self::FILA, array() );

		return is_array( $fila ) ? array_values( array_unique( array_map( 'intval', $fila ) ) ) : array();
	}

	/** Guarda a fila. */
	private static function gravar_fila( $fila ) {
		update_option( self::FILA, array_values( array_unique( array_map( 'intval', $fila ) ) ), false );
	}

	/** Põe uma encomenda na fila, se ainda lá não estiver. */
	private static function enfileirar( $id ) {
		$fila = self::fila();

		if ( in_array( (int) $id, $fila, true ) ) {
			return;
		}

		$fila[] = (int) $id;
		self::gravar_fila( $fila );
	}

	/** Tira uma encomenda da fila. */
	private static function desenfileirar( $id ) {
		self::gravar_fila(
			array_diff( self::fila(), array( (int) $id ) )
		);
	}

	public static function ao_pagar( $id ) {
		$encomenda = wc_get_order( $id );
		if ( ! $encomenda ) {
			return;
		}

		// **Entra na fila antes de se tentar**, e não depois de falhar. Se o PHP
		// morrer a meio do pedido ao Aduela (tempo-limite do alojamento, memória,
		// o processo cortado), a encomenda já está anotada e o cron apanha-a. Ao
		// contrário: uma encomenda paga que ninguém volta a ver.
		self::enfileirar( $encomenda->get_id() );

		self::enviar( Aduela_Cliente::das_definicoes(), $encomenda );
	}

	/** O que o `wp-cron` chama: drena a fila das que ficaram por enviar. */
	public static function enviar_as_que_faltam( $cliente ) {
		$fila = array_slice( self::fila(), 0, self::POR_PASSAGEM );

		foreach ( $fila as $id ) {
			$encomenda = wc_get_order( $id );

			// **Uma encomenda apagada sai da fila.** Sem isto, um identificador
			// órfão ficava lá para sempre a ocupar um dos vinte lugares da
			// passagem, e as que vinham a seguir nunca chegavam a ser tentadas.
			if ( ! $encomenda ) {
				self::desenfileirar( $id );

				continue;
			}

			self::enviar( $cliente, $encomenda );
		}
	}

	/** Manda uma encomenda para o Aduela, e guarda o que aconteceu. */
	private static function enviar( $cliente, $encomenda ) {
		if ( ! $cliente->configurado() ) {
			return;
		}

		/*
		 * **A guarda é "já subiu", e não "já tem venda".** Cartão `36.32`.
		 *
		 * Enquanto foi a venda, uma encomenda à cobrança (que sobe sem venda
		 * nenhuma, e portanto com o número a zero) parecia nunca ter subido, e
		 * era mandada outra vez a cada gancho. O Aduela respondia `repetida` e
		 * nada partia, mas eram pedidos por nada, e a nota na encomenda dizia
		 * "entrou como venda 0", que não quer dizer coisa nenhuma.
		 */
		if ( $encomenda->get_meta( self::META_SUBIU ) ) {
			self::desenfileirar( $encomenda->get_id() );

			return;
		}

		$itens = array();

		foreach ( $encomenda->get_items() as $item ) {
			$produto = $item->get_product();
			if ( ! $produto ) {
				continue;
			}

			$sku = $produto->get_sku();
			if ( '' === $sku ) {
				// **Um artigo sem SKU não sobe, e diz-se.** É o que casa os dois
				// lados: sem ele, o Aduela não tem como saber que artigo é.
				$encomenda->update_meta_data(
					self::META_ERRO,
					sprintf(
						/* translators: %s: nome do produto */
						__( 'O produto "%s" não tem SKU, e é por ele que o Aduela o encontra.', 'aduela-woocommerce' ),
						$produto->get_name()
					)
				);
				$encomenda->save();

				// **Sai da fila.** Repetir dá exatamente o mesmo erro até alguém
				// pôr o SKU no produto, e uma encomenda encravada na fila rouba um
				// dos vinte lugares de cada passagem às que subiriam.
				self::desenfileirar( $encomenda->get_id() );

				return;
			}

			$quantidade = (float) $item->get_quantity();
			$unitario   = $quantidade > 0 ? ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $quantidade : 0;

			$itens[] = array(
				'sku'            => $sku,
				'quantidade'     => (string) $quantidade,
				// **Com IVA e por unidade.** O Aduela emite a fatura com este
				// preço, e tem de ser o que o comprador viu e aceitou.
				'preco_unitario' => wc_format_decimal( $unitario, 4 ),
			);
		}

		if ( empty( $itens ) ) {
			return;
		}

		$corpo = array(
			// A referência é o número da encomenda no WooCommerce, e é a chave
			// de idempotência do lado do Aduela.
			'referencia' => (string) $encomenda->get_order_number(),
			'cliente'    => array(
				'nome'     => trim( $encomenda->get_billing_first_name() . ' ' . $encomenda->get_billing_last_name() ),
				'email'    => $encomenda->get_billing_email(),
				'telefone' => $encomenda->get_billing_phone(),
				'morada'   => $encomenda->get_formatted_billing_address(),
			),
			'itens'      => $itens,
			'entrega'    => array(
				'forma'  => $encomenda->get_shipping_method() ? 'entrega' : 'levantamento',
				'taxa'   => wc_format_decimal( $encomenda->get_shipping_total(), 4 ),
				'morada' => $encomenda->get_formatted_shipping_address(),
			),
			'notas'      => $encomenda->get_customer_note(),
			/*
			 * **Se o dinheiro já entrou.** Cartões `36.30` e `36.32`.
			 *
			 * O Aduela usa isto para decidir em que estado a encomenda entra na
			 * Loja online: paga entra aceite, com a fatura na hora; à cobrança
			 * entra por tratar, e a fatura sai quando o lojista a aceitar.
			 *
			 * **A data de pagamento, e não o `is_paid()`.** Foi assim que nasceu,
			 * e estava errado: o `is_paid()` é baseado no **estado**, e o
			 * WooCommerce considera `processing` como pago. Uma encomenda com
			 * pagamento na entrega entra em `processing` no instante em que é
			 * feita, sem ninguém ter pago nada, e teria emitido fatura-recibo de
			 * dinheiro que ainda está no bolso do comprador.
			 *
			 * O `get_date_paid()` só se preenche quando o `payment_complete`
			 * corre, e esse só corre quando o dinheiro confirma mesmo. Numa
			 * encomenda à cobrança fica vazio até ao fim, que é a verdade.
			 */
			'paga'       => (bool) $encomenda->get_date_paid(),
		);

		$resposta = $cliente->enviar( '/api/v1/integracoes/canais/encomendas', $corpo );

		if ( ! $resposta['ok'] ) {
			// Fica na fila: o Aduela em baixo é a razão de a fila existir.
			self::enfileirar( $encomenda->get_id() );
			$encomenda->update_meta_data( self::META_ERRO, $resposta['erro'] );
			$encomenda->add_order_note(
				sprintf(
					/* translators: %s: mensagem de erro */
					__( 'Aduela: não subiu. %s', 'aduela-woocommerce' ),
					$resposta['erro']
				)
			);
			$encomenda->save();

			return;
		}

		$venda = isset( $resposta['dados']['encomenda']['venda_id'] )
			? (int) $resposta['dados']['encomenda']['venda_id']
			: 0;

		$jaTinhaSubido = (bool) $encomenda->get_meta( self::META_SUBIU );

		$encomenda->update_meta_data( self::META_VENDA, $venda );
		$encomenda->update_meta_data( self::META_SUBIU, time() );
		$encomenda->delete_meta_data( self::META_ERRO );
		self::desenfileirar( $encomenda->get_id() );

		// **A nota diz o que aconteceu mesmo**, e são três coisas diferentes:
		// entrou e foi faturada, entrou e espera pelo lojista, ou já lá estava e
		// agora foi faturada. Uma nota só, a falar sempre de uma venda, mentia em
		// dois dos três casos.
		if ( $venda > 0 && $jaTinhaSubido ) {
			$nota = sprintf(
				/* translators: %d: número da venda no Aduela */
				__( 'Aduela: o pagamento confirmou, e saiu a venda %d.', 'aduela-woocommerce' ),
				$venda
			);
		} elseif ( $venda > 0 ) {
			$nota = sprintf(
				/* translators: %d: número da venda no Aduela */
				__( 'Aduela: entrou como venda %d, com documento emitido.', 'aduela-woocommerce' ),
				$venda
			);
		} else {
			$nota = __(
				'Aduela: entrou na Loja online, por tratar. A fatura sai quando o pagamento confirmar ou quando o lojista a aceitar.',
				'aduela-woocommerce'
			);
		}

		$encomenda->add_order_note( $nota );
		$encomenda->save();
	}

	/** A caixa que mostra o estado, na página da encomenda. */
	public static function caixa_na_encomenda() {
		$ecra = function_exists( 'wc_get_page_screen_id' )
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'aduela-estado',
			__( 'Aduela', 'aduela-woocommerce' ),
			array( __CLASS__, 'desenhar_caixa' ),
			$ecra,
			'side'
		);
	}

	public static function desenhar_caixa( $post_ou_encomenda ) {
		$encomenda = $post_ou_encomenda instanceof WC_Order
			? $post_ou_encomenda
			: wc_get_order( $post_ou_encomenda->ID );

		if ( ! $encomenda ) {
			return;
		}

		$venda = $encomenda->get_meta( self::META_VENDA );
		$erro  = $encomenda->get_meta( self::META_ERRO );

		if ( $venda ) {
			printf(
				'<p><strong>%s</strong><br />%s</p>',
				esc_html__( 'Entrou no Aduela.', 'aduela-woocommerce' ),
				esc_html( sprintf( /* translators: %s: número da venda */ __( 'Venda %s, com documento emitido.', 'aduela-woocommerce' ), $venda ) )
			);

			return;
		}

		if ( $erro ) {
			printf(
				'<p><strong>%s</strong><br />%s</p><p class="description">%s</p>',
				esc_html__( 'Não subiu.', 'aduela-woocommerce' ),
				esc_html( $erro ),
				esc_html__( 'O Aduela volta a tentar sozinho de 15 em 15 minutos.', 'aduela-woocommerce' )
			);

			return;
		}

		printf(
			'<p>%s</p>',
			esc_html__( 'Ainda não subiu. Sobe quando a encomenda ficar paga.', 'aduela-woocommerce' )
		);
	}
}
