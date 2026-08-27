<?php
/**
 * Plugin Name:       Aduela para WooCommerce
 * Plugin URI:        https://docs.aduela.net
 * Description:       Liga a sua loja WooCommerce ao Aduela: o catálogo e o stock descem de lá, e as encomendas sobem para lá, com fatura-recibo angolana emitida no Aduela.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   11.0
 * Author:            Spolly
 * Author URI:        https://aduela.net
 * Text Domain:       aduela-woocommerce
 * Domain Path:       /idiomas
 * License:           GPL-2.0-or-later
 *
 * # O que este plugin é
 *
 * A metade PHP da integração. A outra metade é o módulo `canais` do Aduela, e o
 * motor de sincronização vive lá: aqui não há regras de negócio nenhumas, só a
 * cola entre os ganchos do WooCommerce e a API do Aduela.
 *
 * **Foi assim de propósito.** As regras (idempotência, conflitos, reconciliação)
 * são iguais para o WooCommerce e para a Shopify, e escrevê-las duas vezes daria
 * duas versões que divergem à primeira correção. O que muda é a forma de falar, e
 * é só isso que este ficheiro tem.
 *
 * # O sentido de cada coisa
 *
 *   Catálogo e stock   Aduela  ->  WooCommerce   (pelo `wp-cron`, de 15 em 15 min)
 *   Encomendas         WooCommerce  ->  Aduela   (no gancho, e o cron apanha as que falharem)
 *   Estado             Aduela  ->  WooCommerce   (pelo `wp-cron`)
 *
 * # E porque é que o Aduela manda no catálogo
 *
 * Quem tem o stock a sério é quem o conta na prateleira. Um produto mudado dos
 * dois lados fica pelo que o Aduela diz, e o que se perde fica escrito no
 * registo: é a regra de conflito, e está no `modules/canais/dominio.go`.
 */

defined( 'ABSPATH' ) || exit;

define( 'ADUELA_WC_VERSAO', '1.0.0' );
define( 'ADUELA_WC_FICHEIRO', __FILE__ );
define( 'ADUELA_WC_PASTA', plugin_dir_path( __FILE__ ) );

/**
 * O WooCommerce tem de estar cá, e diz-se em vez de rebentar.
 *
 * **Um plugin que ative sem a dependência dele dá um ecrã branco**, e quem o
 * instalou fica sem saber porquê. Isto avisa, e desativa-se a si próprio.
 */
function aduela_wc_exige_woocommerce() {
	if ( class_exists( 'WooCommerce' ) ) {
		return true;
	}

	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>Aduela:</strong> ';
			echo 'este plugin precisa do WooCommerce, e ele não está ativo. ';
			echo 'Ative o WooCommerce e volte a ativar o Aduela.';
			echo '</p></div>';
		}
	);

	return false;
}

/**
 * A versão do WooCommerce, e o que fazemos com ela.
 *
 * **Diz-se com que versões funciona, em vez de partir em silêncio.** O
 * WooCommerce muda a forma dos ganchos entre versões maiores, e um plugin que
 * assuma a última dá erros que ninguém liga a uma atualização feita há três
 * semanas.
 */
const ADUELA_WC_MINIMA = '7.0';
const ADUELA_WC_TESTADA = '11.0';

function aduela_wc_avisa_da_versao() {
	if ( ! defined( 'WC_VERSION' ) ) {
		return;
	}

	if ( version_compare( WC_VERSION, ADUELA_WC_MINIMA, '<' ) ) {
		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-warning"><p><strong>Aduela:</strong> ' .
					'este plugin foi feito para o WooCommerce %1$s ou mais recente, e aqui está o %2$s. ' .
					'Pode funcionar, e pode falhar sem avisar. Atualize o WooCommerce.' .
					'</p></div>',
					esc_html( ADUELA_WC_MINIMA ),
					esc_html( WC_VERSION )
				);
			}
		);

		return;
	}

	// **E diz-se também quando o WooCommerce é mais novo do que o que testamos.**
	// Um plugin que só avisa para baixo está a afirmar, por omissão, que funciona
	// com tudo o que venha a sair. O WooCommerce muda a forma dos ganchos entre
	// versões maiores, e a pessoa que atualizou merece saber que a integração
	// dela passou a andar em terreno por pisar.
	if ( version_compare( WC_VERSION, ADUELA_WC_TESTADA, '>' ) ) {
		add_action(
			'admin_notices',
			static function () {
				printf(
					'<div class="notice notice-info is-dismissible"><p><strong>Aduela:</strong> ' .
					'esta versão do plugin foi testada até ao WooCommerce %1$s, e aqui está o %2$s. ' .
					'Deve funcionar. Se alguma coisa deixar de subir, diga-nos a versão.' .
					'</p></div>',
					esc_html( ADUELA_WC_TESTADA ),
					esc_html( WC_VERSION )
				);
			}
		);
	}
}

require_once ADUELA_WC_PASTA . 'includes/class-aduela-cliente.php';
require_once ADUELA_WC_PASTA . 'includes/class-aduela-definicoes.php';
require_once ADUELA_WC_PASTA . 'includes/class-aduela-sincronizacao.php';
require_once ADUELA_WC_PASTA . 'includes/class-aduela-encomendas.php';

/** Arranca o plugin, depois de os outros carregarem. */
function aduela_wc_arrancar() {
	if ( ! aduela_wc_exige_woocommerce() ) {
		return;
	}

	aduela_wc_avisa_da_versao();

	Aduela_Definicoes::registar();
	Aduela_Sincronizacao::registar();
	Aduela_Encomendas::registar();
}
add_action( 'plugins_loaded', 'aduela_wc_arrancar' );

/**
 * Diz ao WooCommerce que este plugin sabe viver com a tabela nova de encomendas.
 *
 * **Sem esta declaração, o WooCommerce marca o plugin como incompatível** e, numa
 * loja que ainda não migrou, recusa-se a deixar ligar o armazenamento novo
 * (o HPOS). O lojista fica com um aviso vermelho no painel e sem perceber que o
 * culpado é a integração dele com o Aduela.
 *
 * E é verdade que sabe: as encomendas por subir vivem numa fila nossa, e não
 * numa consulta com `meta_query`, que é o que não funciona lá. Ver o
 * `class-aduela-encomendas.php`.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			ADUELA_WC_FICHEIRO,
			true
		);
	}
);

/**
 * Ao ativar, marca o `wp-cron` e mais nada.
 *
 * **Não sincroniza aqui.** A ativação corre no pedido de quem carregou no botão,
 * e uma sincronização de trezentos artigos nesse pedido dá um tempo-limite do
 * PHP e um plugin que parece não ter ativado.
 */
function aduela_wc_ao_ativar() {
	if ( ! wp_next_scheduled( Aduela_Sincronizacao::GANCHO_DO_CRON ) ) {
		wp_schedule_event( time() + 60, 'aduela_quinze_minutos', Aduela_Sincronizacao::GANCHO_DO_CRON );
	}
}
register_activation_hook( __FILE__, 'aduela_wc_ao_ativar' );

/** Ao desativar, tira o `wp-cron`. Deixá-lo marcado é deixar lixo. */
function aduela_wc_ao_desativar() {
	wp_clear_scheduled_hook( Aduela_Sincronizacao::GANCHO_DO_CRON );
}
register_deactivation_hook( __FILE__, 'aduela_wc_ao_desativar' );

/**
 * De quinze em quinze minutos.
 *
 * **Não de cinco em cinco**, e não é preguiça: o `wp-cron` do WordPress corre nas
 * visitas ao site, e numa loja com pouco movimento ele já é irregular. Um
 * intervalo curto dá a ilusão de tempo real e o mesmo atraso real, com mais
 * pedidos ao Aduela.
 */
function aduela_wc_intervalos( $intervalos ) {
	$intervalos['aduela_quinze_minutos'] = array(
		'interval' => 15 * MINUTE_IN_SECONDS,
		'display'  => __( 'A cada 15 minutos (Aduela)', 'aduela-woocommerce' ),
	);

	return $intervalos;
}
add_filter( 'cron_schedules', 'aduela_wc_intervalos' );
