# Contribuir

Este plugin é a metade PHP de uma integração. A outra metade é o módulo `canais`
do Aduela, e é lá que vivem as regras de negócio: idempotência, resolução de
conflitos e reconciliação. **Aqui há só a cola** entre os ganchos do WooCommerce
e a API.

Vale a pena saber isso antes de propor uma alteração, porque decide onde ela
pertence.

## O que se corrige aqui

Tudo o que é do lado do WordPress: ganchos, o ecrã de definições, o HPOS, a fila
de encomendas por enviar, mensagens de erro, traduções, compatibilidade com
versões do WooCommerce.

## O que não se corrige aqui

O que decide **o quê** é sincronizado e **com que regra**. Se o catálogo traz o
artigo errado, se uma encomenda repetida devia ou não criar uma venda nova, se o
stock devia vir de outro armazém: isso é do Aduela, e uma correção feita deste
lado passa a ser uma segunda opinião que diverge da primeira à primeira
alteração.

Nesse caso, abra um *issue* a descrever o comportamento que viu. Ele vai para o
outro lado, e volta cá quando a API mudar.

## Como correr isto

Não há suite de testes automatizada, e diz-se em vez de ficar por dizer. O que se
faz é instalar o plugin numa loja WooCommerce de ensaio, ligá-lo a um Aduela, e
percorrer os dois sentidos:

1. **Catálogo para baixo:** criar um artigo no Aduela com um SKU que a loja já
   tenha, carregar em **Sincronizar agora**, e confirmar que o preço e o stock
   mudaram no WooCommerce.
2. **Encomendas para cima:** fazer uma encomenda na loja, pagá-la, e confirmar
   que a venda aparece no Aduela com a referência `WOO-<número>`.

## Estilo

O código segue os
[WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
para PHP: tabulações, chavetas na mesma linha, espaços dentro dos parênteses, e
tudo o que sai para o ecrã passa por `esc_html`, `esc_attr` ou `esc_url`.

**Os comentários explicam o porquê, e não o quê.** Um comentário que repete o
nome da função não vale a linha que ocupa; um que diz porque é que a chave nunca
se relê poupa uma hora a quem lá chegar daqui a um ano.

Escrevem-se em **português europeu**, como o resto do plugin, porque o produto é
angolano e é nessa língua que se fala com quem o usa.

## Licença

Ao contribuir, aceita que o seu trabalho seja distribuído sob a
[GPL-2.0-or-later](LICENSE), que é a licença deste plugin e a que qualquer plugin
de WordPress distribuído tem de ter.
