<?php
/**
 * O catálogo do Aduela, e o botão que o põe à venda nesta loja.
 *
 * # Porque é que isto existe
 *
 * A sincronização casa pelo SKU e sobrepõe preço e existência. **O que ela nunca
 * fez foi criar um produto**, e a razão estava escrita: criar montra por conta do
 * lojista enche o site dele de fichas sem fotografia, sem categoria e sem texto,
 * num sítio que é a cara do negócio.
 *
 * Essa razão continua a valer **para a criação automática e calada**, e é por
 * isso que ela nasce desligada nas definições. **Não vale para um botão que o
 * lojista carrega**: aí é ele que está a decidir, que era exatamente o que se lhe
 * queria deixar. Este ecrã é esse botão.
 *
 * # O que este ecrã mostra, e porque é lado a lado
 *
 * O catálogo do Aduela **com o que esta loja tem ao lado**. Uma lista só dos que
 * faltam responderia à pergunta de hoje e a mais nenhuma: quem abre isto quer
 * saber se o SKU que escreveu à mão casou, e para isso tem de ver os dois lados.
 *
 * # A fotografia vem, e o resto da montra não
 *
 * **A fotografia da ficha do artigo vem**, e fica copiada na biblioteca do
 * WordPress: um produto criado aqui nasce com cara. O que não vem é o resto da
 * montra, e é de propósito: categorias, etiquetas, texto de venda e SEO são o
 * trabalho de quem sabe vender no site, e o ERP não sabe.
 *
 * **O IVA também não vem.** As classes de imposto configuram-se no WooCommerce,
 * e traduzir aqui a taxa do Aduela para uma delas daria dois sítios a decidir a
 * mesma percentagem, que é a forma mais fácil de faturar mal.
 */

defined( 'ABSPATH' ) || exit;

class Aduela_Catalogo {

	const CAPACIDADE = 'manage_woocommerce';

	/** Quantos artigos por página. Uma loja com dois mil não cabe num ecrã. */
	const POR_PAGINA = 50;

	public static function registar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_aduela_publicar', array( __CLASS__, 'publicar_um' ) );
		add_action( 'admin_post_aduela_publicar_todos', array( __CLASS__, 'publicar_todos' ) );
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Catálogo do Aduela', 'aduela-woocommerce' ),
			__( 'Catálogo do Aduela', 'aduela-woocommerce' ),
			self::CAPACIDADE,
			'aduela-wc-catalogo',
			array( __CLASS__, 'desenhar' )
		);
	}

	/**
	 * Traz o catálogo do Aduela e casa cada artigo com o produto desta loja.
	 *
	 * Devolve `array( 'artigos' => [...], 'erro' => '' )`. Cada artigo leva um
	 * `produto_id` (zero quando a loja não o tem) e o `estado` do produto, que é
	 * o que distingue um rascunho de uma coisa à venda.
	 */
	public static function ler( $cliente = null ) {
		$cliente = $cliente ? $cliente : Aduela_Cliente::das_definicoes();

		if ( ! $cliente->configurado() ) {
			return array(
				'artigos' => array(),
				'erro'    => __( 'Falta o endereço ou a chave.', 'aduela-woocommerce' ),
			);
		}

		$resposta = $cliente->obter( '/api/v1/integracoes/canais/catalogo' );

		if ( ! $resposta['ok'] ) {
			return array( 'artigos' => array(), 'erro' => $resposta['erro'] );
		}

		$brutos  = isset( $resposta['dados']['artigos'] ) ? $resposta['dados']['artigos'] : array();
		$artigos = array();

		foreach ( $brutos as $bruto ) {
			$sku = isset( $bruto['sku'] ) ? trim( (string) $bruto['sku'] ) : '';

			// **Um artigo sem SKU não se mostra sequer.** Não há por onde o casar
			// nem por onde o criar, e listá-lo com o botão desligado seria
			// convidar alguém a carregar nele à espera de alguma coisa.
			if ( '' === $sku ) {
				continue;
			}

			$id     = wc_get_product_id_by_sku( $sku );
			$estado = '';

			if ( $id ) {
				$produto = wc_get_product( $id );
				$estado  = $produto ? $produto->get_status() : '';
			}

			$artigos[] = array(
				'sku'        => $sku,
				'nome'       => isset( $bruto['nome'] ) ? (string) $bruto['nome'] : $sku,
				'descricao'  => isset( $bruto['descricao'] ) ? (string) $bruto['descricao'] : '',
				'codigo'     => isset( $bruto['codigo_barras'] ) ? (string) $bruto['codigo_barras'] : '',
				'imagem'     => isset( $bruto['imagem_url'] ) ? (string) $bruto['imagem_url'] : '',
				/*
				 * **O preço que vai para a loja é o final, com imposto.**
				 *
				 * O Aduela guarda preços sem imposto e acrescenta-o ao faturar;
				 * uma montra mostra ao consumidor o que ele paga. Enquanto aqui
				 * se usou o `preco`, a loja cobrava menos imposto do que a
				 * fatura dizia, e o comprador recebia um documento com um total
				 * que nunca viu.
				 *
				 * O `preco` continua a vir, e é a base sem imposto. O
				 * `preco_com_iva` é o que se publica.
				 */
				'preco'      => isset( $bruto['preco_com_iva'] ) ? $bruto['preco_com_iva']
					: ( isset( $bruto['preco'] ) ? $bruto['preco'] : '' ),
				'preco_base' => isset( $bruto['preco'] ) ? $bruto['preco'] : '',
				'taxa_iva'   => isset( $bruto['taxa_iva'] ) ? $bruto['taxa_iva'] : '',
				'existencia' => isset( $bruto['existencia'] ) ? $bruto['existencia'] : 0,
				'produto_id' => (int) $id,
				'estado'     => $estado,
			);
		}

		return array( 'artigos' => $artigos, 'erro' => '' );
	}

	/**
	 * Cria o produto nesta loja, ou atualiza-o se o SKU já cá estiver.
	 *
	 * **Carregar duas vezes não duplica.** O SKU é a chave, e um SKU que já
	 * exista leva o caminho da atualização em vez do da criação. É o mesmo
	 * princípio da sincronização, e tem de ser: dois produtos com o mesmo SKU
	 * partem o casamento das encomendas, e a partir daí ninguém sabe qual é qual.
	 *
	 * O `$estado` é `publish` ou `draft`, e vem de qual dos botões se carregou.
	 *
	 * Devolve o `WC_Product` gravado, ou um `WP_Error`.
	 */
	public static function publicar( $artigo, $estado = 'publish' ) {
		$sku = isset( $artigo['sku'] ) ? trim( (string) $artigo['sku'] ) : '';

		if ( '' === $sku ) {
			return new WP_Error( 'aduela_sem_sku', __( 'O artigo veio sem SKU.', 'aduela-woocommerce' ) );
		}

		$estado = in_array( $estado, array( 'publish', 'draft' ), true ) ? $estado : 'publish';

		/*
		 * **Aceita as duas formas do artigo**, a do `ler()` e a que vem crua da
		 * resposta do Aduela. A sincronização periódica chama isto com a segunda,
		 * e obrigá-la a traduzir primeiro daria a tradução escrita em dois
		 * sítios, que é como as duas divergem.
		 */
		$nome      = isset( $artigo['nome'] ) ? (string) $artigo['nome'] : '';
		$descricao = isset( $artigo['descricao'] ) ? (string) $artigo['descricao'] : '';
		// **O preço com imposto**, que é o que o comprador paga e o que a fatura
		// do Aduela vai totalizar. O `preco` cru é a base sem imposto: publicá-lo
		// fazia a loja cobrar menos do que o documento diz. Cartão `36.31`.
		$preco = '';
		if ( isset( $artigo['preco_com_iva'] ) && '' !== $artigo['preco_com_iva'] ) {
			$preco = $artigo['preco_com_iva'];
		} elseif ( isset( $artigo['preco'] ) ) {
			$preco = $artigo['preco'];
		}

		$codigo = '';
		if ( ! empty( $artigo['codigo'] ) ) {
			$codigo = (string) $artigo['codigo'];
		} elseif ( ! empty( $artigo['codigo_barras'] ) ) {
			$codigo = (string) $artigo['codigo_barras'];
		}

		$imagem = '';
		if ( ! empty( $artigo['imagem'] ) ) {
			$imagem = (string) $artigo['imagem'];
		} elseif ( ! empty( $artigo['imagem_url'] ) ) {
			$imagem = (string) $artigo['imagem_url'];
		}

		$id      = wc_get_product_id_by_sku( $sku );
		$produto = $id ? wc_get_product( $id ) : new WC_Product_Simple();

		if ( ! $produto ) {
			return new WP_Error( 'aduela_produto', __( 'O WooCommerce não devolveu o produto.', 'aduela-woocommerce' ) );
		}

		if ( ! $id ) {
			$produto->set_sku( $sku );
			$produto->set_status( $estado );

			// **O nome e a descrição só se escrevem na criação.** Depois disso são
			// do lojista: ele reescreve o título para vender, e uma passagem que
			// lho sobrepusesse apagava-lhe o trabalho de cada quinze minutos.
			$produto->set_name( '' !== $nome ? $nome : $sku );

			// A descrição do Aduela é a linha da ficha do artigo, e vai para o
			// resumo: o corpo fica livre para o texto de venda, que é o que o
			// lojista quer escrever e o ERP não sabe.
			if ( '' !== $descricao ) {
				$produto->set_short_description( $descricao );
			}

			$produto->set_catalog_visibility( 'visible' );

			// O código de barras cabe no campo do WooCommerce desde a 9.2, e antes
			// disso não existe. Guardado sem o obrigar: um plugin que rebente numa
			// loja com a versão anterior não serve de nada.
			if ( '' !== $codigo && method_exists( $produto, 'set_global_unique_id' ) ) {
				$produto->set_global_unique_id( $codigo );
			}
		}

		if ( '' !== $preco && null !== $preco ) {
			$produto->set_regular_price( wc_format_decimal( $preco ) );
		}

		$existencia = wc_stock_amount( isset( $artigo['existencia'] ) ? $artigo['existencia'] : 0 );
		$produto->set_manage_stock( true );
		$produto->set_stock_quantity( $existencia );
		$produto->set_stock_status( $existencia > 0 ? 'instock' : 'outofstock' );

		$gravado = $produto->save();

		if ( ! $gravado ) {
			return new WP_Error( 'aduela_gravar', __( 'O WooCommerce não gravou o produto.', 'aduela-woocommerce' ) );
		}

		// A marca serve para se saber, daqui a um ano, que este produto nasceu
		// aqui e não foi escrito à mão. Sem ela não há como distinguir os dois.
		if ( ! $id ) {
			update_post_meta( $gravado, '_aduela_criado', time() );
		}

		self::trazer_a_imagem( $gravado, $imagem );

		return wc_get_product( $gravado );
	}

	/**
	 * Traz a fotografia do Aduela para a biblioteca do WordPress.
	 *
	 * # Porquê copiar, em vez de apontar
	 *
	 * O produto podia guardar o endereço do Aduela e mostrá-lo diretamente. **Não
	 * se faz**, por três razões: a montra ficava a depender do servidor do ERP
	 * estar de pé para mostrar as imagens; o WooCommerce não conseguia gerar os
	 * tamanhos de que precisa (miniatura, catálogo, ampliação); e o lojista
	 * deixava de poder trocar a fotografia por uma melhor, que é uma coisa que ele
	 * vai querer fazer.
	 *
	 * Copiada uma vez, a imagem é dele.
	 *
	 * # Uma imagem que falhe não desfaz o produto
	 *
	 * A fotografia é o menos importante do que aqui se cria: um produto sem
	 * imagem vende, um produto que não existe não. Se o endereço não responder,
	 * ou se o ficheiro não for uma imagem, o produto fica na mesma e a fotografia
	 * não vem. **Não se lança erro**, e é de propósito.
	 *
	 * # E não se volta a descarregar a cada quinze minutos
	 *
	 * O endereço de origem fica gravado. Uma passagem seguinte que o veja igual
	 * não faz nada; só quando ele mudar no Aduela é que a imagem vem outra vez. Um
	 * `media_sideload_image` por artigo em cada passagem seria descarregar o
	 * catálogo inteiro de quinze em quinze minutos.
	 */
	private static function trazer_a_imagem( $produto_id, $url ) {
		$url = trim( (string) $url );

		// **A validação a sério é a do WordPress, e faz-se mais abaixo.** Aqui só
		// se recusa o que nem endereço é: o `wp_http_validate_url` rejeita a rede
		// privada, e chamá-lo agora rejeitava o Aduela de quem o corre no servidor
		// da própria loja antes de o gancho o poder autorizar.
		$partes = $url ? wp_parse_url( $url ) : false;

		if ( ! $partes || empty( $partes['host'] ) ||
			! in_array( isset( $partes['scheme'] ) ? $partes['scheme'] : '', array( 'http', 'https' ), true ) ) {
			return;
		}

		if ( get_post_meta( $produto_id, '_aduela_imagem_url', true ) === $url ) {
			return;
		}

		// **Uma imagem posta à mão não se substitui.** Se o lojista já lá pôs uma
		// fotografia melhor do que a da ficha do artigo, a sincronização não tem
		// nada que lha tirar.
		if ( ! get_post_meta( $produto_id, '_aduela_imagem_url', true ) && has_post_thumbnail( $produto_id ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ganchos = self::deixar_ir_ao_aduela( $url );

		$anexo = media_sideload_image( $url, $produto_id, null, 'id' );

		if ( $ganchos ) {
			remove_filter( 'http_request_host_is_external', $ganchos['anfitriao'], 10 );
			remove_filter( 'http_allowed_safe_ports', $ganchos['porta'], 10 );
		}

		if ( is_wp_error( $anexo ) ) {
			return;
		}

		set_post_thumbnail( $produto_id, $anexo );
		update_post_meta( $produto_id, '_aduela_imagem_url', $url );
	}

	/**
	 * Deixa o WordPress ir buscar a imagem quando o Aduela está na rede local.
	 *
	 * # O problema, e apanhou-se a testar
	 *
	 * O `media_sideload_image` desce por `wp_safe_remote_get`, e essa recusa
	 * qualquer endereço cujo anfitrião resolva para um IP privado. **Faz muito bem
	 * por defeito**: é o que impede um endereço vindo de fora de pôr o WordPress a
	 * bater em máquinas da rede interna de quem o aloja.
	 *
	 * Só que o Aduela pode **ser** uma máquina da rede interna. Um cliente que o
	 * corra no servidor da loja tem as fotografias dos artigos num endereço
	 * privado, e o produto nascia sempre sem imagem, em silêncio.
	 *
	 * # O que se abre, e é o mínimo
	 *
	 * **Só o anfitrião do Aduela que está configurado neste ecrã**, e só enquanto
	 * dura o descarregamento. Um endereço de qualquer outra máquina privada
	 * continua a ser recusado, mesmo que venha no catálogo: o que se confia é no
	 * servidor a que este plugin já entrega a chave, e em mais nenhum.
	 *
	 * Na nuvem isto não chega a fazer nada: as fotografias ficam num endereço
	 * público, que passa na validação sem ajuda nenhuma.
	 *
	 * # E são duas recusas, e não uma
	 *
	 * A do IP privado é a que se vê primeiro. Por baixo dela há outra: o WordPress
	 * só aceita as portas **80, 443 e 8080**, e recusa as outras sem consultar o
	 * gancho do anfitrião. Uma fotografia num MinIO na 9000, ou um Aduela na 8086,
	 * caía aí, depois de o primeiro gancho já ter dito que sim. Apanhado a testar,
	 * e é o género de coisa que só aparece a correr.
	 *
	 * Devolve os dois ganchos que pôs, para quem chamou os tirar; ou `null`.
	 */
	private static function deixar_ir_ao_aduela( $url ) {
		$alvo = wp_parse_url( $url, PHP_URL_HOST );

		$opcoes = Aduela_Definicoes::opcoes();
		$nosso  = $opcoes['endereco'] ? wp_parse_url( $opcoes['endereco'], PHP_URL_HOST ) : '';

		if ( ! $alvo || ! $nosso || strtolower( $alvo ) !== strtolower( $nosso ) ) {
			return null;
		}

		$porta = wp_parse_url( $url, PHP_URL_PORT );

		$anfitriao = static function ( $externo, $anfitriao ) use ( $alvo ) {
			return strtolower( (string) $anfitriao ) === strtolower( $alvo ) ? true : $externo;
		};

		$portas = static function ( $permitidas, $anfitriao ) use ( $alvo, $porta ) {
			if ( $porta && strtolower( (string) $anfitriao ) === strtolower( $alvo ) ) {
				$permitidas[] = (int) $porta;
			}

			return $permitidas;
		};

		add_filter( 'http_request_host_is_external', $anfitriao, 10, 2 );
		add_filter( 'http_allowed_safe_ports', $portas, 10, 2 );

		return array( 'anfitriao' => $anfitriao, 'porta' => $portas );
	}

	/** O botão de uma linha: publica ou cria como rascunho um artigo só. */
	public static function publicar_um() {
		if ( ! current_user_can( self::CAPACIDADE ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'aduela-woocommerce' ) );
		}

		$sku = isset( $_GET['sku'] ) ? sanitize_text_field( wp_unslash( $_GET['sku'] ) ) : '';
		check_admin_referer( 'aduela_publicar_' . $sku );

		$estado = isset( $_GET['estado'] ) && 'draft' === $_GET['estado'] ? 'draft' : 'publish';

		$catalogo = self::ler();

		if ( $catalogo['erro'] ) {
			self::dizer_mal( $catalogo['erro'] );
			self::voltar();
		}

		$achado = null;
		foreach ( $catalogo['artigos'] as $artigo ) {
			if ( $artigo['sku'] === $sku ) {
				$achado = $artigo;
				break;
			}
		}

		if ( ! $achado ) {
			/* translators: %s: SKU do artigo */
			self::dizer_mal( sprintf( __( 'O Aduela já não tem nenhum artigo com o SKU %s.', 'aduela-woocommerce' ), $sku ) );
			self::voltar();
		}

		$feito = self::publicar( $achado, $estado );

		if ( is_wp_error( $feito ) ) {
			self::dizer_mal( $feito->get_error_message() );
			self::voltar();
		}

		self::dizer_bem(
			sprintf(
				/* translators: 1: nome do produto, 2: estado (publicado ou rascunho) */
				__( '%1$s criado nesta loja, como %2$s.', 'aduela-woocommerce' ),
				$feito->get_name(),
				'draft' === $estado
					? __( 'rascunho', 'aduela-woocommerce' )
					: __( 'publicado', 'aduela-woocommerce' )
			)
		);

		self::voltar();
	}

	/**
	 * O botão de cima: cria de uma vez todos os que a loja não tem.
	 *
	 * **Só os que faltam.** Passar por cima dos que já existem seria repor-lhes o
	 * preço e o stock, que é trabalho da sincronização e não deste botão; e quem
	 * carrega aqui está a pensar nos que faltam, não numa reposição geral.
	 */
	public static function publicar_todos() {
		if ( ! current_user_can( self::CAPACIDADE ) ) {
			wp_die( esc_html__( 'Sem permissão.', 'aduela-woocommerce' ) );
		}

		check_admin_referer( 'aduela_publicar_todos' );

		$estado = isset( $_POST['estado'] ) && 'draft' === $_POST['estado'] ? 'draft' : 'publish';

		$catalogo = self::ler();

		if ( $catalogo['erro'] ) {
			self::dizer_mal( $catalogo['erro'] );
			self::voltar();
		}

		$criados = 0;
		$falhas  = array();

		foreach ( $catalogo['artigos'] as $artigo ) {
			if ( $artigo['produto_id'] ) {
				continue;
			}

			$feito = self::publicar( $artigo, $estado );

			if ( is_wp_error( $feito ) ) {
				$falhas[] = $artigo['sku'] . ': ' . $feito->get_error_message();
				continue;
			}

			++$criados;
		}

		$mensagem = sprintf(
			/* translators: 1: quantos produtos, 2: estado (publicados ou rascunhos) */
			_n( '%1$d produto criado, como %2$s.', '%1$d produtos criados, como %2$s.', $criados, 'aduela-woocommerce' ),
			$criados,
			'draft' === $estado
				? __( 'rascunhos', 'aduela-woocommerce' )
				: __( 'publicados', 'aduela-woocommerce' )
		);

		// **As falhas dizem-se, e não se somam ao sucesso.** Um botão que diga
		// "criados 8" quando eram 10 e duas rebentaram é a mesma classe de erro
		// que este cartão veio corrigir.
		if ( $falhas ) {
			$mensagem .= ' ' . sprintf(
				/* translators: %s: lista de SKUs que falharam */
				__( 'Falharam: %s', 'aduela-woocommerce' ),
				implode( '; ', array_slice( $falhas, 0, 5 ) )
			);
		}

		self::dizer_bem( $mensagem );
		self::voltar();
	}

	private static function dizer_bem( $mensagem ) {
		set_transient( 'aduela_wc_catalogo', $mensagem, 60 );
	}

	private static function dizer_mal( $mensagem ) {
		set_transient( 'aduela_wc_catalogo_erro', $mensagem, 60 );
	}

	private static function voltar() {
		wp_safe_redirect( admin_url( 'admin.php?page=aduela-wc-catalogo' ) );
		exit;
	}

	public static function desenhar() {
		$catalogo = self::ler();
		$artigos  = $catalogo['artigos'];

		$bom = get_transient( 'aduela_wc_catalogo' );
		$mau = get_transient( 'aduela_wc_catalogo_erro' );
		delete_transient( 'aduela_wc_catalogo' );
		delete_transient( 'aduela_wc_catalogo_erro' );

		$faltam = 0;
		foreach ( $artigos as $artigo ) {
			if ( ! $artigo['produto_id'] ) {
				++$faltam;
			}
		}

		$paginas = max( 1, (int) ceil( count( $artigos ) / self::POR_PAGINA ) );
		$pagina  = isset( $_GET['pagina'] ) ? max( 1, min( $paginas, (int) $_GET['pagina'] ) ) : 1;
		$nesta   = array_slice( $artigos, ( $pagina - 1 ) * self::POR_PAGINA, self::POR_PAGINA );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Catálogo do Aduela', 'aduela-woocommerce' ); ?></h1>

			<p>
				<?php esc_html_e( 'O que está à venda no Aduela, com o que esta loja tem ao lado. Publicar cria o produto no WooCommerce com o mesmo SKU, e a partir daí a sincronização trata do preço e do stock.', 'aduela-woocommerce' ); ?>
				<br />
				<?php esc_html_e( 'Os preços vêm já com IVA, porque é esse que o comprador paga e o que a fatura do Aduela vai totalizar.', 'aduela-woocommerce' ); ?>
			</p>

			<?php if ( $bom ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $bom ); ?></p></div>
			<?php endif; ?>

			<?php if ( $mau ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $mau ); ?></p></div>
			<?php endif; ?>

			<?php if ( $catalogo['erro'] ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html( $catalogo['erro'] ); ?></p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=aduela-wc' ) ); ?>">
							<?php esc_html_e( 'Ver as definições do Aduela', 'aduela-woocommerce' ); ?>
						</a>
					</p>
				</div>
			<?php elseif ( ! $artigos ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'O Aduela respondeu, e não tem nenhum artigo ativo com SKU. Um artigo sem SKU não se consegue casar nem criar.', 'aduela-woocommerce' ); ?></p>
				</div>
			<?php else : ?>

				<p>
					<?php
					printf(
						/* translators: 1: total de artigos, 2: quantos faltam nesta loja */
						esc_html__( '%1$d artigos no Aduela, e %2$d ainda não existem nesta loja.', 'aduela-woocommerce' ),
						count( $artigos ),
						(int) $faltam
					);
					?>
				</p>

				<?php if ( $faltam ) : ?>
					<p style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="aduela_publicar_todos" />
							<input type="hidden" name="estado" value="publish" />
							<?php wp_nonce_field( 'aduela_publicar_todos' ); ?>
							<?php
							submit_button(
								sprintf(
									/* translators: %d: quantos artigos faltam */
									_n( 'Publicar o %d que falta', 'Publicar os %d que faltam', $faltam, 'aduela-woocommerce' ),
									$faltam
								),
								'primary',
								'submit',
								false
							);
							?>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<input type="hidden" name="action" value="aduela_publicar_todos" />
							<input type="hidden" name="estado" value="draft" />
							<?php wp_nonce_field( 'aduela_publicar_todos' ); ?>
							<?php submit_button( __( 'Criar como rascunhos', 'aduela-woocommerce' ), 'secondary', 'submit', false ); ?>
						</form>
					</p>

					<p class="description" style="margin-bottom:1rem">
						<?php esc_html_e( 'Um produto criado aqui traz a fotografia da ficha do artigo, quando ela existe. O que não traz é categoria nem texto de venda, e o IVA fica pelas regras de imposto desta loja, porque é aí que elas se configuram.', 'aduela-woocommerce' ); ?>
					</p>
				<?php endif; ?>

				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:12rem"><?php esc_html_e( 'SKU', 'aduela-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Nome no Aduela', 'aduela-woocommerce' ); ?></th>
							<th style="width:9rem"><?php esc_html_e( 'Preço com IVA', 'aduela-woocommerce' ); ?></th>
							<th style="width:7rem"><?php esc_html_e( 'Existência', 'aduela-woocommerce' ); ?></th>
							<th style="width:11rem"><?php esc_html_e( 'Nesta loja', 'aduela-woocommerce' ); ?></th>
							<th style="width:14rem"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $nesta as $artigo ) : ?>
							<tr>
								<td><code><?php echo esc_html( $artigo['sku'] ); ?></code></td>
								<td><?php echo esc_html( $artigo['nome'] ); ?></td>
								<td><?php echo wp_kses_post( wc_price( (float) $artigo['preco'] ) ); ?></td>
								<td><?php echo esc_html( wc_stock_amount( $artigo['existencia'] ) ); ?></td>
								<td>
									<?php if ( ! $artigo['produto_id'] ) : ?>
										<span style="color:#b32d2e"><?php esc_html_e( 'Não existe', 'aduela-woocommerce' ); ?></span>
									<?php else : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $artigo['produto_id'] ) ); ?>">
											<?php
											echo esc_html(
												'publish' === $artigo['estado']
													? __( 'Publicado', 'aduela-woocommerce' )
													: sprintf(
														/* translators: %s: estado do produto no WordPress */
														__( 'Existe (%s)', 'aduela-woocommerce' ),
														$artigo['estado']
													)
											);
											?>
										</a>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! $artigo['produto_id'] ) : ?>
										<a
											class="button button-primary"
											href="<?php echo esc_url( self::ligacao( $artigo['sku'], 'publish' ) ); ?>"
										><?php esc_html_e( 'Publicar', 'aduela-woocommerce' ); ?></a>

										<a
											class="button"
											href="<?php echo esc_url( self::ligacao( $artigo['sku'], 'draft' ) ); ?>"
										><?php esc_html_e( 'Rascunho', 'aduela-woocommerce' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $paginas > 1 ) : ?>
					<p style="margin-top:1rem">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'pagina', '%#%' ),
									'format'  => '',
									'current' => $pagina,
									'total'   => $paginas,
								)
							)
						);
						?>
					</p>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/** A ligação de uma linha, com o nonce do SKU dela. */
	private static function ligacao( $sku, $estado ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'aduela_publicar',
					'sku'    => rawurlencode( $sku ),
					'estado' => $estado,
				),
				admin_url( 'admin-post.php' )
			),
			'aduela_publicar_' . $sku
		);
	}
}
