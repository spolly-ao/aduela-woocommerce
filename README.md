# Aduela para WooCommerce

Liga uma loja WooCommerce ao Aduela: **o catálogo e o stock descem de lá, e as
encomendas sobem para lá**, com fatura-recibo angolana emitida no Aduela.

A outra metade da integração é o módulo `canais` do Aduela, e é lá que vivem as
regras: idempotência, conflitos e reconciliação são iguais para o WooCommerce e
para a Shopify, e escrevê-las duas vezes daria duas versões que divergem à
primeira correção. **Aqui há só a cola** entre os ganchos do WooCommerce e a API.

## O sentido de cada coisa

| O quê | Sentido | Quando |
|---|---|---|
| Catálogo, preço e existência | Aduela para WooCommerce | `wp-cron`, de 15 em 15 minutos |
| Encomendas | WooCommerce para Aduela | No gancho do pagamento, e o cron apanha as que falharem |

**O Aduela manda no catálogo, e o canal manda nas encomendas.** Quem tem o stock
a sério é quem o conta na prateleira. O que é da montra (descrição longa,
categorias, etiquetas, SEO) fica do lojista, porque é ele que sabe vender no site
dele.

**Casa-se pelo SKU.** O nome muda; o SKU é o que ninguém mexe depois de o
começar a usar.

## Pôr os artigos do Aduela à venda

Em **WooCommerce → Catálogo do Aduela** está o catálogo inteiro, com o que esta
loja já tem ao lado. Cada artigo que a loja não tenha traz dois botões:

- **Publicar** cria o produto e põe-no à venda;
- **Rascunho** cria-o em rascunho, para lhe mexer antes de o mostrar.

E em cima há os dois equivalentes para **todos os que faltam de uma vez**.

Um produto criado assim leva o SKU, o nome, a descrição da ficha, o preço, a
existência e **a fotografia do artigo**, copiada para a biblioteca do WordPress.
Não leva categoria nem texto de venda, e o IVA fica pelas regras de imposto da
loja: são as decisões de quem vende no site, e o ERP não as sabe.

**Carregar duas vezes não duplica.** O SKU é a chave: um artigo que já cá esteja
atualiza-se em vez de nascer outra vez.

### E automaticamente?

Em **WooCommerce → Aduela** há a opção **Artigos que esta loja não tem**, com
três respostas: `Ignorar`, `Criar como rascunho` e `Criar e publicar`. Ela decide
o que a sincronização de quinze em quinze minutos faz.

**Nasce em Ignorar**, de propósito: um produto criado sozinho vai direto para a
montra sem ninguém o ter visto. Quem preferir escolher um a um tem o ecrã de
cima; quem quiser a loja toda em espelho põe a opção em `Criar e publicar`.

Enquanto estiver em `Ignorar`, o ecrã conta quantos artigos ficaram de fora, para
que a decisão não seja silenciosa.

## Instalar

1. No Aduela, em **Canais de venda**, criar um canal do tipo WooCommerce e
   escolher o armazém. **A chave aparece uma vez.**
2. No WordPress, **Plugins → Adicionar novo → Carregar plugin**, escolher o
   `aduela-woocommerce.zip`, instalar e ativar.
3. Em **WooCommerce → Aduela**, escrever o endereço do Aduela e a chave, gravar,
   e carregar em **Testar a ligação**.
4. Carregar em **Sincronizar agora** para não esperar pelo `wp-cron`, e ler o
   que ele diz: quantos artigos vieram, quantos foram atualizados, e quantos
   não existem nesta loja.
5. Se o último número não for zero, ir a **WooCommerce → Catálogo do Aduela** e
   publicar os que faltam.

Precisa de WordPress 6.0 ou mais recente, PHP 7.4 ou mais recente, e WooCommerce
7.0 ou mais recente. **Testado até ao WooCommerce 11.0**, e o plugin diz no
painel quando a versão instalada está fora desse intervalo, para os dois lados.

Funciona com o armazenamento novo de encomendas do WooCommerce (o HPOS) e com o
antigo: as encomendas por subir vivem numa fila nossa, e não numa consulta com
`meta_query`, que naquele não é suportada.

## Licença, e porque é aberto

**GPL-2.0-or-later** ([texto](LICENSE)), que é a licença de qualquer plugin de
WordPress que se distribua, e está no cabeçalho do plugin desde a primeira
versão.

O código vive aqui, à vista, e é deliberado: **este plugin corre no servidor de
outra pessoa, e pede-lhe uma chave**. Quem instala isto tem o direito de ler o
que ele faz com a chave dela, que pedidos manda, e o que grava na base de dados
da loja. Uma integração fechada que pede credenciais é uma coisa que se aceita
por confiança; esta pode ser lida.

**Abrir o plugin não abre o Aduela.** São dois programas: aqui está a cola, e as
regras de negócio vivem do outro lado. O que este repositório mostra é
exatamente o que corre na loja do cliente, nem mais nem menos.

## Como se distribui

**Como ficheiro `.zip`**, das versões deste repositório ou de `docs.aduela.net`,
e não pelo diretório do WordPress. Duas razões:

1. **O plugin não serve a ninguém sem uma conta Aduela.** O diretório existe para
   descoberta, e este plugin não tem nada para descobrir: quem o instala já é
   cliente e já tem a chave na mão. Uma página no diretório com centenas de
   instalações de gente que não tem conta é suporte a dobrar, e nenhuma venda.
2. **A revisão do diretório não se agenda.** É uma fila com semanas, e cada
   correção volta a passar por ela. Uma integração que tem de acompanhar as
   versões do Aduela não pode ter o seu calendário decidido por terceiros.

**O que se perde, e diz-se:** as atualizações automáticas que o diretório dá de
borla. Enquanto não houver um servidor de atualizações próprio, uma versão nova
descarrega-se e instala-se por cima, como a primeira. É trabalho por fazer, e
fica dito em vez de ficar por dizer.

## Fazer o ficheiro de distribuição

```bash
cd ..
zip -r aduela-woocommerce.zip aduela-woocommerce -x '*.git*'
```

## O que fica gravado no WordPress

| Chave | O que é |
|---|---|
| `aduela_wc_definicoes` | O endereço e a chave do canal |
| `aduela_wc_estado` | A última sincronização: quantos artigos vieram, quantos mexeram, quantos não existem cá, e o último erro |
| `aduela_wc_fila` | As encomendas que ainda não subiram |
| `_aduela_venda_id` | Na encomenda: o número da venda no Aduela |
| `_aduela_erro` | Na encomenda: o motivo de não ter subido |
| `_aduela_criado` | No produto: quando o plugin o criou. Distingue-o de um escrito à mão |
| `_aduela_imagem_url` | No produto: de onde veio a fotografia, para não voltar a descarregá-la |

Desativar o plugin tira o `wp-cron` e deixa o resto: reativar não perde a
ligação nem repete encomendas.

## Contribuir

Ver o [CONTRIBUTING.md](CONTRIBUTING.md). Em resumo: o que é do WordPress
corrige-se aqui, e o que é regra de negócio pertence ao módulo `canais` do
Aduela, do outro lado da API.
