<?php
/**
 * O catálogo e o stock, que descem do Aduela.
 *
 * # A regra de conflito, e é a mesma dos dois canais
 *
 * **O Aduela manda no catálogo, e o canal manda nas encomendas.** Um produto
 * mudado dos dois lados fica pelo que o Aduela diz, e o que se perdeu fica no
 * registo. A razão é simples: quem tem o stock a sério é quem o conta na
 * prateleira.
 *
 * Na prática, isto sobrepõe o preço e a existência de cada artigo que case pelo
 * SKU. **Não toca no que é da montra**: título, descrição longa, imagens,
 * categorias e SEO ficam do lojista, porque é ele que sabe vender no site dele.
 *
 * # E o que acontece depois de horas sem ligação
 *
 * Nada de especial, e é o ponto. Cada passagem lê o catálogo inteiro e repõe o
 * que estiver diferente: não há estado acumulado para se perder, e por isso a
 * reconciliação depois de uma noite em baixo é só a passagem seguinte.
 */

defined( 'ABSPATH' ) || exit;

class Aduela_Sincronizacao {

	const GANCHO_DO_CRON = 'aduela_wc_sincronizar';
	const ESTADO         = 'aduela_wc_estado';

	public static function registar() {
		add_action( self::GANCHO_DO_CRON, array( __CLASS__, 'passar' ) );
	}

	/** O que o ecrã de definições mostra. */
	public static function estado() {
		$gravado = get_option( self::ESTADO, array() );
		$gravado = is_array( $gravado ) ? $gravado : array();

		$quando = isset( $gravado['quando'] ) ? (int) $gravado['quando'] : 0;

		return array(
			'quando'      => $quando
				? human_time_diff( $quando ) . ' ' . __( 'atrás', 'aduela-woocommerce' )
				: __( 'Ainda não correu.', 'aduela-woocommerce' ),
			'artigos'     => isset( $gravado['artigos'] ) ? (int) $gravado['artigos'] : 0,
			'vieram'      => isset( $gravado['vieram'] ) ? (int) $gravado['vieram'] : 0,
			'sem_produto' => isset( $gravado['sem_produto'] ) ? (int) $gravado['sem_produto'] : 0,
			'criados'     => isset( $gravado['criados'] ) ? (int) $gravado['criados'] : 0,
			'erro'        => isset( $gravado['erro'] ) ? $gravado['erro'] : '',
			'por_enviar'  => Aduela_Encomendas::quantas_por_enviar(),
		);
	}

	/**
	 * Uma passagem completa: catálogo para baixo, encomendas para cima.
	 *
	 * Devolve o que fez, para o botão de sincronizar agora o poder dizer. O
	 * `wp-cron` ignora o valor, e é por isso que ele também se grava na opção do
	 * estado: a passagem automática não tem a quem responder.
	 */
	public static function passar() {
		$cliente = Aduela_Cliente::das_definicoes();

		if ( ! $cliente->configurado() ) {
			return array(
				'artigos'     => 0,
				'vieram'      => 0,
				'sem_produto' => 0,
				'criados'     => 0,
				'erro'        => __( 'Falta o endereço ou a chave.', 'aduela-woocommerce' ),
			);
		}

		$resultado = self::descer_catalogo( $cliente );

		// **As encomendas por enviar vão a seguir, e na mesma passagem.** É o que
		// apanha as que falharam no gancho porque o Aduela estava em baixo: sem
		// isto, uma encomenda perdida ficava perdida até alguém reparar.
		Aduela_Encomendas::enviar_as_que_faltam( $cliente );

		update_option(
			self::ESTADO,
			array(
				'quando'      => time(),
				'artigos'     => $resultado['artigos'],
				'vieram'      => $resultado['vieram'],
				'sem_produto' => $resultado['sem_produto'],
				'criados'     => $resultado['criados'],
				'erro'        => $resultado['erro'],
			),
			false
		);

		return $resultado;
	}

	/**
	 * Traz o catálogo e repõe preço e existência no WooCommerce.
	 *
	 * **Casa pelo SKU**, e não pelo nome nem pelo identificador. O nome muda, e o
	 * identificador é de cada sistema; o SKU é o que ninguém mexe depois de o
	 * cliente o começar a usar.
	 *
	 * **Um artigo que o WooCommerce não tenha é ignorado por defeito.** Criar
	 * produtos sem ninguém pedir era criar montra por conta do lojista:
	 * fotografias em falta, descrições vazias e categorias erradas, num sítio que
	 * é a cara do negócio dele.
	 *
	 * **Mas a decisão passou a ser dele, e não do plugin.** Quem quiser que a
	 * passagem os crie liga-o nas definições, como rascunho ou já publicados; e
	 * quem quiser escolher um a um tem o ecrã do catálogo. Enquanto estiver em
	 * `nao`, o que se conta é quantos ficaram de fora, que é o número sem o qual
	 * ninguém percebe que a bola está do lado dele.
	 */
	private static function descer_catalogo( $cliente ) {
		$resposta = $cliente->obter( '/api/v1/integracoes/canais/catalogo' );

		if ( ! $resposta['ok'] ) {
			return array(
				'artigos'     => 0,
				'vieram'      => 0,
				'sem_produto' => 0,
				'criados'     => 0,
				'erro'        => $resposta['erro'],
			);
		}

		$artigos = isset( $resposta['dados']['artigos'] ) ? $resposta['dados']['artigos'] : array();
		$mexidos = 0;

		/*
		 * Quantos artigos do Aduela é que esta loja não tem.
		 *
		 * **É o número que faltava para isto se perceber.** Um lojista que ligue
		 * o plugin e não veja nada acontecer não tem como saber se o Aduela não
		 * mandou nada ou se mandou e nenhum SKU casou; e como a passagem salta
		 * em silêncio o que não encontra, as duas situações davam o mesmo ecrã.
		 */
		$sem_produto = 0;

		/*
		 * **O que fazer com um artigo que esta loja não tem**, e vem das
		 * definições: `nao`, `rascunho` ou `publicar`.
		 *
		 * Nasce em `nao`, que é o que o plugin sempre fez, e por duas razões. Uma
		 * é não mudar o comportamento de quem já o tem instalado por causa de uma
		 * atualização. A outra é a de sempre: um produto criado sozinho vai direto
		 * para a montra sem fotografia e sem categoria, e ninguém o viu antes de
		 * ele lá estar.
		 *
		 * Quem quer escolher um a um tem o ecrã do catálogo, que é onde a decisão
		 * é mesmo de quem carrega.
		 */
		$opcoes  = Aduela_Definicoes::opcoes();
		$criar   = isset( $opcoes['criar_em_falta'] ) ? $opcoes['criar_em_falta'] : 'nao';
		$criados = 0;

		foreach ( $artigos as $artigo ) {
			$sku = isset( $artigo['sku'] ) ? trim( (string) $artigo['sku'] ) : '';
			if ( '' === $sku ) {
				continue;
			}

			$id = wc_get_product_id_by_sku( $sku );
			if ( ! $id ) {
				if ( 'nao' === $criar ) {
					++$sem_produto;
					continue;
				}

				$feito = Aduela_Catalogo::publicar(
					$artigo,
					'rascunho' === $criar ? 'draft' : 'publish'
				);

				// **Uma criação falhada conta como em falta, e não como criada.**
				// O número existe para dizer o que não está na loja, e uma falha
				// deixa o artigo exatamente onde estava.
				if ( is_wp_error( $feito ) ) {
					++$sem_produto;
					continue;
				}

				++$criados;
				continue;
			}

			$produto = wc_get_product( $id );
			if ( ! $produto ) {
				continue;
			}

			$mudou = false;

			if ( isset( $artigo['preco'] ) ) {
				$preco = wc_format_decimal( $artigo['preco'] );

				if ( wc_format_decimal( $produto->get_regular_price() ) !== $preco ) {
					$produto->set_regular_price( $preco );
					$mudou = true;
				}
			}

			if ( isset( $artigo['existencia'] ) ) {
				$existencia = wc_stock_amount( $artigo['existencia'] );

				$produto->set_manage_stock( true );
				if ( wc_stock_amount( $produto->get_stock_quantity() ) !== $existencia ) {
					$produto->set_stock_quantity( $existencia );
					$mudou = true;
				}

				// **Esgotado escreve-se, e não se deduz.** Um produto com zero e
				// à venda deixa alguém comprar o que não há, e a encomenda
				// rebenta do lado do Aduela por falta de stock.
				$estado = $existencia > 0 ? 'instock' : 'outofstock';
				if ( $produto->get_stock_status() !== $estado ) {
					$produto->set_stock_status( $estado );
					$mudou = true;
				}
			}

			if ( $mudou ) {
				$produto->save();
				++$mexidos;
			}
		}

		return array(
			'artigos'     => $mexidos,
			'vieram'      => count( $artigos ),
			'sem_produto' => $sem_produto,
			'criados'     => $criados,
			'erro'        => '',
		);
	}
}
