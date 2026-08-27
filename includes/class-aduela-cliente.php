<?php
/**
 * O cliente HTTP do Aduela.
 *
 * Um sítio só a falar com a API, para o resto do plugin não repetir cabeçalhos,
 * tempos-limite e tratamento de erros em cada chamada.
 */

defined( 'ABSPATH' ) || exit;

class Aduela_Cliente {

	/** O cabeçalho onde a chave do canal viaja. Tem de bater certo com o core. */
	const CABECALHO = 'X-Aduela-Canal';

	/**
	 * Quanto tempo se espera por uma resposta.
	 *
	 * **Vinte segundos, e não os cinco por omissão do WordPress.** Este plugin
	 * corre em alojamentos partilhados angolanos a falar com um servidor que pode
	 * estar em Frankfurt: cinco segundos dá tempos-limite constantes numa
	 * integração que está a funcionar.
	 */
	const ESPERA = 20;

	private $base;
	private $chave;

	public function __construct( $base, $chave ) {
		$this->base  = untrailingslashit( trim( (string) $base ) );
		$this->chave = trim( (string) $chave );
	}

	/** Monta o cliente a partir do que está gravado nas definições. */
	public static function das_definicoes() {
		$opcoes = Aduela_Definicoes::opcoes();

		return new self( $opcoes['endereco'], $opcoes['chave'] );
	}

	/** Diz se há endereço e chave para sequer tentar. */
	public function configurado() {
		return '' !== $this->base && '' !== $this->chave;
	}

	/** GET numa rota do canal. */
	public function obter( $caminho ) {
		return $this->pedir( 'GET', $caminho, null );
	}

	/** POST numa rota do canal. */
	public function enviar( $caminho, $corpo, $metodo = 'POST' ) {
		return $this->pedir( $metodo, $caminho, $corpo );
	}

	/**
	 * Faz o pedido e desembrulha o envelope do Aduela.
	 *
	 * # O que devolve, e porquê assim
	 *
	 * Devolve um `array` com `ok`, `dados`, `estado` e `erro`, e **nunca atira**.
	 * Um plugin do WordPress que atire numa chamada de rede derruba a página de
	 * quem estava a comprar, e o pior momento para isso é exatamente o momento em
	 * que o Aduela está em baixo.
	 *
	 * O `erro` vem em português, tirado do envelope: o Aduela escreve mensagens
	 * para quem vende, e passá-las tal e qual é melhor do que inventar as nossas.
	 */
	private function pedir( $metodo, $caminho, $corpo ) {
		if ( ! $this->configurado() ) {
			return array(
				'ok'     => false,
				'estado' => 0,
				'erro'   => __( 'O Aduela ainda não está configurado. Ponha o endereço e a chave em Aduela → Definições.', 'aduela-woocommerce' ),
			);
		}

		$argumentos = array(
			'method'  => $metodo,
			'timeout' => self::ESPERA,
			'headers' => array(
				self::CABECALHO => $this->chave,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				// Diz quem está a chamar. Do outro lado, um registo com
				// "WordPress/6.4" e mais nada não ajuda a perceber nada.
				'User-Agent'    => 'Aduela-WooCommerce/' . ADUELA_WC_VERSAO . '; ' . home_url(),
			),
		);

		if ( null !== $corpo ) {
			$argumentos['body'] = wp_json_encode( $corpo );
		}

		$resposta = wp_remote_request( $this->base . $caminho, $argumentos );

		if ( is_wp_error( $resposta ) ) {
			return array(
				'ok'     => false,
				'estado' => 0,
				'erro'   => $resposta->get_error_message(),
			);
		}

		$estado = (int) wp_remote_retrieve_response_code( $resposta );
		$lido   = json_decode( wp_remote_retrieve_body( $resposta ), true );

		if ( ! is_array( $lido ) ) {
			return array(
				'ok'     => false,
				'estado' => $estado,
				'erro'   => sprintf(
					/* translators: %d: código HTTP */
					__( 'O Aduela respondeu %d e o corpo não se percebe.', 'aduela-woocommerce' ),
					$estado
				),
			);
		}

		if ( $estado >= 200 && $estado < 300 && ! empty( $lido['success'] ) ) {
			return array(
				'ok'     => true,
				'estado' => $estado,
				'dados'  => isset( $lido['data'] ) ? $lido['data'] : array(),
			);
		}

		$mensagem = isset( $lido['error']['message'] )
			? $lido['error']['message']
			: __( 'O Aduela recusou o pedido, e não disse porquê.', 'aduela-woocommerce' );

		return array(
			'ok'     => false,
			'estado' => $estado,
			'codigo' => isset( $lido['error']['code'] ) ? $lido['error']['code'] : '',
			'erro'   => $mensagem,
		);
	}
}
