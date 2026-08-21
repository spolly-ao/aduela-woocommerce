<?php
/**
 * As encomendas, que sobem para o Aduela. Cartão `33.3`.
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
		// **No `payment_complete` e no `completed`**, e não no `new_order`: uma
		// encomenda por pagar não é uma venda, e emitir fatura-recibo de uma
		// coisa que ninguém pagou é emitir um documento errado.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'ao_pagar' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'ao_pagar' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'caixa_na_encomenda' ) );
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

		if ( $encomenda->get_meta( self::META_VENDA ) ) {
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

		$encomenda->update_meta_data( self::META_VENDA, $venda );
		$encomenda->delete_meta_data( self::META_ERRO );
		self::desenfileirar( $encomenda->get_id() );
		$encomenda->add_order_note(
			sprintf(
				/* translators: %d: número da venda no Aduela */
				__( 'Aduela: entrou como venda %d, com documento emitido.', 'aduela-woocommerce' ),
				$venda
			)
		);
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
