# Aduela para WooCommerce

Liga uma loja WooCommerce ao Aduela: **o catálogo e o stock descem de lá, e as
encomendas sobem para lá**, com fatura-recibo angolana emitida no Aduela.

A outra metade da integração é o módulo `canais` do núcleo
(`core/modules/canais`), e é lá que vivem as regras. Aqui há só a cola entre os
ganchos do WooCommerce e a API. Cartão `33.3`.

## O sentido de cada coisa

| O quê | Sentido | Quando |
|---|---|---|
| Catálogo, preço e existência | Aduela para WooCommerce | `wp-cron`, de 15 em 15 minutos |
| Encomendas | WooCommerce para Aduela | No gancho do pagamento, e o cron apanha as que falharem |

**O Aduela manda no catálogo, e o canal manda nas encomendas.** Quem tem o stock
a sério é quem o conta na prateleira. O que é da montra (título, descrição longa,
imagens, categorias, SEO) fica do lojista, porque é ele que sabe vender no site
dele.

**Casa-se pelo SKU.** O nome muda; o SKU é o que ninguém mexe depois de o
começar a usar. Um artigo do Aduela que a loja não tenha é ignorado, e não
criado: criar produtos aqui era criar montra por conta do lojista, com
fotografias em falta e categorias erradas, num sítio que é a cara do negócio.

## Instalar

1. No Aduela, em **Canais de venda**, criar um canal do tipo WooCommerce e
   escolher o armazém. **A chave aparece uma vez.**
2. No WordPress, **Plugins → Adicionar novo → Carregar plugin**, escolher o
   `aduela-woocommerce.zip`, instalar e ativar.
3. Em **WooCommerce → Aduela**, escrever o endereço do Aduela e a chave, gravar,
   e carregar em **Testar a ligação**.

Precisa de WordPress 6.0 ou mais recente, PHP 7.4 ou mais recente, e WooCommerce
7.0 ou mais recente. **Testado até ao WooCommerce 11.0**, e o plugin diz no
painel quando a versão instalada está fora desse intervalo, para os dois lados.

Funciona com o armazenamento novo de encomendas do WooCommerce (o HPOS) e com o
antigo: as encomendas por subir vivem numa fila nossa, e não numa consulta com
`meta_query`, que naquele não é suportada.

## Como se distribui, e porquê assim

**Como ficheiro `.zip`, descarregado de `docs.aduela.net`, e não pelo diretório
do WordPress.** A decisão é do cartão `33.3`, e as razões são três:

1. **O plugin não serve a ninguém sem uma conta Aduela.** O diretório existe para
   descoberta, e este plugin não tem nada para descobrir: quem o instala já é
   cliente e já tem a chave na mão. Uma página no diretório com centenas de
   instalações de gente que não tem conta é suporte a dobrar, e nenhuma venda.
2. **A revisão do diretório não se agenda.** É uma fila com semanas, e cada
   correção volta a passar por ela. Uma integração que tem de acompanhar as
   versões do Aduela não pode ter o seu calendário decidido por terceiros.
3. **A licença.** O diretório exige GPL, e o Aduela vende a interface embutida à
   parte (cartão `13.4`). Publicar metade do produto sob GPL para ganhar
   descoberta que não queremos era começar uma discussão de licenciamento por
   nada.

**O que se perde, e diz-se:** as atualizações automáticas que o diretório dá de
borla. Enquanto não houver um servidor de atualizações próprio, uma versão nova
descarrega-se e instala-se por cima, como a primeira. É trabalho por fazer, e
está nomeado no cartão em vez de ficar por dizer.

## Fazer o ficheiro de distribuição

```bash
cd plugins/woocommerce
zip -r aduela-woocommerce.zip aduela-woocommerce -x '*.git*'
```

## O que fica gravado no WordPress

| Chave | O que é |
|---|---|
| `aduela_wc_definicoes` | O endereço e a chave do canal |
| `aduela_wc_estado` | A última sincronização, quantos artigos mexeram e o último erro |
| `aduela_wc_fila` | As encomendas que ainda não subiram |
| `_aduela_venda_id` | Na encomenda: o número da venda no Aduela |
| `_aduela_erro` | Na encomenda: o motivo de não ter subido |

Desativar o plugin tira o `wp-cron` e deixa o resto: reativar não perde a
ligação nem repete encomendas.
