<?php
/**
 * O ecrã de definições, no painel do WordPress.
 *
 * Duas coisas para escrever (o endereço e a chave), dois botões, e o estado da
 * última sincronização. Mais nada: tudo o resto configura-se no Aduela, e
 * duplicá-lo aqui daria dois sítios a decidir a mesma coisa.
 *
 * # Os dois botões respondem a perguntas diferentes
 *
 * **Testar a ligação** pergunta *"ele responde-me?"*: pede o catálogo e diz
 * quantos artigos vieram, sem tocar em nada da loja. É o que se carrega depois
 * de colar a chave.
 *
 * **Sincronizar agora** faz a passagem toda, como o `wp-cron` faria: repõe preços
 * e existências, e manda as encomendas que ficaram por enviar. Existe porque o
 * `wp-cron` corre nas visitas ao site, e numa loja com pouco movimento pode
 * demorar horas a acontecer, o que numa instalação nova parece uma avaria.
 */

defined( 'ABSPATH' ) || exit;

class Aduela_Definicoes {

	const OPCAO      = 'aduela_wc_definicoes';
	const CAPACIDADE = 'manage_woocommerce';

	public static function registar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'campos' ) );
		add_action( 'admin_post_aduela_testar', array( __CLASS__, 'testar' ) );
		add_action( 'admin_post_aduela_sincronizar', array( __CLASS__, 'sincronizar' ) );
	}

	/** O que está gravado, com os valores de origem por cima. */
	public static function opcoes() {
		$gravado = get_option( self::OPCAO, array() );

		return wp_parse_args(
			is_array( $gravado ) ? $gravado : array(),
			array(
				'endereco' => '',
				'chave'    => '',
			)
		);
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Aduela', 'aduela-woocommerce' ),
			__( 'Aduela', 'aduela-woocommerce' ),
			self::CAPACIDADE,
			'aduela-wc',
			array( __CLASS__, 'desenhar' )
		);
	}

	public static function campos() {
		register_setting(
			'aduela_wc',
			self::OPCAO,
			array( 'sanitize_callback' => array( __CLASS__, 'limpar' ) )
		);
	}

	/**
	 * Limpa o que veio do formulário.
	 *
	 * **A chave em branco não apaga a que está lá.** O campo mostra-se vazio de
	 * propósito (uma chave não se relê), e gravar as definições depois de mexer
	 * só no endereço não pode apagar a ligação: era o defeito mais fácil de
	 * escrever aqui, e o mais chato de diagnosticar.
	 */
	public static function limpar( $bruto ) {
		$atual = self::opcoes();

		$endereco = isset( $bruto['endereco'] ) ? esc_url_raw( trim( $bruto['endereco'] ) ) : '';
		$chave    = isset( $bruto['chave'] ) ? sanitize_text_field( trim( $bruto['chave'] ) ) : '';

		return array(
			'endereco' => $endereco,
			'chave'    => '' !== $chave ? $chave : $atual['chave'],
		);
	}

	/** O botão de testar: pede o catálogo e diz quantos artigos vieram. */
	public static function testar() {
		if ( ! current_user_can( self::CAPACIDADE ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'aduela-woocommerce' ) );
		}

		check_admin_referer( 'aduela_testar' );

		$resposta = Aduela_Cliente::das_definicoes()->obter( '/api/v1/integracoes/canais/catalogo' );

		if ( $resposta['ok'] ) {
			$quantos = isset( $resposta['dados']['artigos'] ) ? count( $resposta['dados']['artigos'] ) : 0;

			set_transient(
				'aduela_wc_teste',
				sprintf(
					/* translators: %d: número de artigos */
					__( 'Ligado. O Aduela respondeu com %d artigos.', 'aduela-woocommerce' ),
					$quantos
				),
				60
			);
		} else {
			set_transient( 'aduela_wc_teste_erro', $resposta['erro'], 60 );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=aduela-wc' ) );
		exit;
	}

	/**
	 * O botão de sincronizar agora: faz a passagem e diz o que fez.
	 *
	 * # A mensagem conta três números, e não um
	 *
	 * Quantos vieram do Aduela, quantos foram atualizados aqui, e **quantos não
	 * existem nesta loja**. O terceiro é o que faltava: o plugin nunca cria
	 * produtos, e sem esse número um lojista com dez artigos no Aduela e nenhum
	 * no WooCommerce via uma sincronização "bem sucedida" que não mexeu em nada,
	 * sem forma nenhuma de perceber porquê.
	 */
	public static function sincronizar() {
		if ( ! current_user_can( self::CAPACIDADE ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'aduela-woocommerce' ) );
		}

		check_admin_referer( 'aduela_sincronizar' );

		$feito = Aduela_Sincronizacao::passar();

		if ( ! empty( $feito['erro'] ) ) {
			set_transient( 'aduela_wc_teste_erro', $feito['erro'], 60 );

			wp_safe_redirect( admin_url( 'admin.php?page=aduela-wc' ) );
			exit;
		}

		$mensagem = sprintf(
			/* translators: 1: artigos recebidos, 2: artigos atualizados */
			__( 'Sincronizado. Vieram %1$d artigos do Aduela, e %2$d foram atualizados aqui.', 'aduela-woocommerce' ),
			(int) $feito['vieram'],
			(int) $feito['artigos']
		);

		if ( ! empty( $feito['sem_produto'] ) ) {
			$mensagem .= ' ' . sprintf(
				/* translators: %d: artigos sem produto correspondente */
				_n(
					'%d artigo do Aduela não existe nesta loja e foi ignorado: o plugin não cria produtos, casa-os pelo SKU.',
					'%d artigos do Aduela não existem nesta loja e foram ignorados: o plugin não cria produtos, casa-os pelo SKU.',
					(int) $feito['sem_produto'],
					'aduela-woocommerce'
				),
				(int) $feito['sem_produto']
			);
		}

		set_transient( 'aduela_wc_teste', $mensagem, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=aduela-wc' ) );
		exit;
	}

	public static function desenhar() {
		$opcoes = self::opcoes();
		$estado = Aduela_Sincronizacao::estado();

		$bom  = get_transient( 'aduela_wc_teste' );
		$mau  = get_transient( 'aduela_wc_teste_erro' );
		delete_transient( 'aduela_wc_teste' );
		delete_transient( 'aduela_wc_teste_erro' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Aduela', 'aduela-woocommerce' ); ?></h1>

			<p>
				<?php esc_html_e( 'O catálogo e o stock descem do Aduela para esta loja, e as encomendas sobem para lá, com fatura-recibo emitida no Aduela.', 'aduela-woocommerce' ); ?>
			</p>

			<?php if ( $bom ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $bom ); ?></p></div>
			<?php endif; ?>

			<?php if ( $mau ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $mau ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'aduela_wc' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="aduela-endereco"><?php esc_html_e( 'Endereço do Aduela', 'aduela-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								id="aduela-endereco"
								class="regular-text"
								name="<?php echo esc_attr( self::OPCAO ); ?>[endereco]"
								value="<?php echo esc_attr( $opcoes['endereco'] ); ?>"
								placeholder="https://app.aduela.net"
							/>
							<p class="description">
								<?php esc_html_e( 'Na nuvem é https://app.aduela.net. No seu servidor, o endereço dele.', 'aduela-woocommerce' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="aduela-chave"><?php esc_html_e( 'Chave do canal', 'aduela-woocommerce' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								id="aduela-chave"
								class="regular-text"
								name="<?php echo esc_attr( self::OPCAO ); ?>[chave]"
								value=""
								autocomplete="off"
								placeholder="<?php echo $opcoes['chave'] ? esc_attr__( 'Gravada. Escreva outra para a trocar.', 'aduela-woocommerce' ) : 'adu_canal_…'; ?>"
							/>
							<p class="description">
								<?php esc_html_e( 'No Aduela, em Canais de venda, crie um canal do tipo WooCommerce. A chave aparece uma vez.', 'aduela-woocommerce' ); ?>
								<br />
								<?php esc_html_e( 'Este campo nasce vazio mesmo quando há chave gravada: uma chave não se relê. Deixá-lo em branco não a apaga.', 'aduela-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Estado', 'aduela-woocommerce' ); ?></h2>

			<table class="widefat striped" style="max-width:48rem">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'Última sincronização', 'aduela-woocommerce' ); ?></td>
						<td><strong><?php echo esc_html( $estado['quando'] ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Artigos que vieram do Aduela', 'aduela-woocommerce' ); ?></td>
						<td><strong><?php echo esc_html( $estado['vieram'] ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Artigos atualizados na última passagem', 'aduela-woocommerce' ); ?></td>
						<td><strong><?php echo esc_html( $estado['artigos'] ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Do Aduela, e sem produto nesta loja', 'aduela-woocommerce' ); ?></td>
						<td>
							<strong><?php echo esc_html( $estado['sem_produto'] ); ?></strong>
							<?php if ( $estado['sem_produto'] ) : ?>
								<p class="description">
									<?php esc_html_e( 'O plugin casa pelo SKU e não cria produtos. Crie-os no WooCommerce com o mesmo SKU do Aduela, e a passagem seguinte trata do preço e do stock.', 'aduela-woocommerce' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Encomendas por enviar', 'aduela-woocommerce' ); ?></td>
						<td><strong><?php echo esc_html( $estado['por_enviar'] ); ?></strong></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Último erro', 'aduela-woocommerce' ); ?></td>
						<td><?php echo esc_html( $estado['erro'] ? $estado['erro'] : __( 'Nenhum.', 'aduela-woocommerce' ) ); ?></td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top:1rem;display:flex;gap:.5rem;align-items:center">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="aduela_sincronizar" />
					<?php wp_nonce_field( 'aduela_sincronizar' ); ?>
					<?php submit_button( __( 'Sincronizar agora', 'aduela-woocommerce' ), 'primary', 'submit', false ); ?>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<input type="hidden" name="action" value="aduela_testar" />
					<?php wp_nonce_field( 'aduela_testar' ); ?>
					<?php submit_button( __( 'Testar a ligação', 'aduela-woocommerce' ), 'secondary', 'submit', false ); ?>
				</form>
			</p>

			<p class="description">
				<?php esc_html_e( 'A sincronização corre sozinha de 15 em 15 minutos, pelo wp-cron. Numa loja com pouco movimento o wp-cron é irregular, porque corre nas visitas ao site.', 'aduela-woocommerce' ); ?>
			</p>
		</div>
		<?php
	}
}
