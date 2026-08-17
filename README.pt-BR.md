> 🌍 [English](README.md) · **Português (Brasil)**

# GLPI Wallpaper

Plugin para **GLPI 11** que hospeda imagens de papel de parede em URLs públicas e fixas,
para serem consumidas pela política `Personalization/DesktopImageUrl` do
**Microsoft Intune**.

O envio da imagem é protegido pelos perfis do GLPI; o download é anônimo, porque o
Windows busca a imagem no contexto `SYSTEM` da máquina — sem sessão e sem cookie.

## Por que existe

Não há plugin equivalente no ecossistema GLPI. O
[Branding](https://help.glpi-project.org/doc-plugins/plugin-glpi-network/branding)
só muda o fundo da tela de login do próprio GLPI, e o
[phonebg](https://github.com/monta990/phonebg) gera papéis de parede de celular a partir
do inventário. Nenhum dos dois entrega uma imagem para um MDM consumir.

## Como funciona

Dois canais fixos, cada um com uma URL que **nunca muda**:

| Canal | Para que serve | URL |
|---|---|---|
| `producao` | frota inteira | `https://SEU-GLPI/plugins/wallpaper/producao.jpg` |
| `piloto` | grupo de teste | `https://SEU-GLPI/plugins/wallpaper/piloto.jpg` |

O fluxo foi desenhado para a equipe validar antes de atingir todo mundo:

1. Envie a imagem nova para o canal **piloto**
2. O grupo de teste no Intune já aponta para a URL do piloto — valide numa máquina real
3. Clique em **Promover piloto → produção**
4. A URL de produção não muda; a frota pega a imagem nova na próxima sincronização

Trocar o papel de parede nunca exige mexer na política do Intune.

### Por que a URL termina em `.jpg`

O Personalization CSP **classifica o tipo do arquivo** e informa o resultado pelo
`DesktopImageStatus`, onde o código **`4` significa "Unknown file type"**. A documentação
da Microsoft descreve o valor sempre como *"uma URL http ou https para uma imagem jpg,
jpeg ou png"*, com exemplos que sempre trazem a extensão explícita.

Por isso a URL pública é uma rota nativa do GLPI 11
([`src/Controller/ImageController.php`](wallpaper/src/Controller/ImageController.php))
terminada em extensão de imagem, e não `image.php?c=producao`.

A extensão pedida **não decide o que é servido** — o `Content-Type` vem sempre do arquivo
real. Se você trocar a imagem de PNG para JPEG, a URL antiga continua respondendo e a
política do Intune não quebra; o painel apenas avisa da divergência para você alinhar
quando for conveniente.

A rota legada continua disponível e funciona sempre, independentemente da configuração do
servidor web: `https://SEU-GLPI/plugins/wallpaper/front/image.php?c=producao`

### Servidor web: deixe o caminho `.jpg` chegar ao PHP

A maioria das instalações do GLPI tem uma regra de arquivos estáticos que intercepta
`.jpg`, `.png`, `.css` e afins, servindo direto do disco. Essa regra engole a URL bonita:
a requisição nunca chega ao PHP e o servidor web devolve o **404 dele**, não o do GLPI.

Confira primeiro:

```bash
curl -s https://SEU-GLPI/plugins/wallpaper/piloto.jpg | head -5
```

Se o corpo mencionar `nginx` ou `Apache`, a requisição está sendo interceptada. Adicione
uma exceção **antes** da regra de estáticos.

**Nginx** — o `^~` dá prioridade a este location sobre as regras com regex:

```nginx
location ^~ /plugins/wallpaper/ {
    try_files $uri /index.php$is_args$args;
}
```

**Apache** — dentro do `<VirtualHost>` do GLPI ou no `.htaccess`:

```apache
RewriteEngine On
RewriteRule ^plugins/wallpaper/(producao|piloto)\.(jpe?g|png)$ /index.php [L,QSA]
```

Recarregue o servidor web e teste de novo. Enquanto isso não estiver no lugar, use a rota
legada na política do Intune e leia a ressalva em
[docs/INTUNE.pt-BR.md](docs/INTUNE.pt-BR.md).

## Instalação

```bash
cd /var/www/glpi/plugins
git clone https://github.com/rafaelfariasbsb/glpi-wallpaper.git
mv glpi-wallpaper/wallpaper .    # o diretório do plugin precisa se chamar "wallpaper"
chown -R www-data:www-data wallpaper
```

Depois instale e ative o *Wallpaper* em **Configurar → Plug-ins**.

Há duas portas de entrada para o painel:

- **O botão de chave inglesa** no cartão do plugin, em **Configurar → Plug-ins**.
  Disponível assim que o plugin é ativado, sem precisar reabrir a sessão.
- **Configurar → Wallpaper** na barra lateral. Este exige sessão nova: o GLPI guarda em
  cache tanto o menu (`$_SESSION['glpimenu']`) quanto os direitos do perfil no momento do
  login, então **saia e entre de novo** depois de instalar.

O plugin guarda as imagens em `files/_plugins/wallpaper/`, criado na instalação. Ele nunca
escreve no próprio diretório de código.

### A entrada do menu não aparece

| Verifique | Como |
|---|---|
| Precisa entrar agora? | Use o botão de chave inglesa no cartão do plugin, em **Configurar → Plug-ins** |
| Abriu uma sessão nova? | Saia e entre de novo — o menu é montado no login |
| Seu perfil tem o direito? | **Administração → Perfis → (seu perfil) → Wallpaper**, marque ao menos *Ler* e salve |
| O painel abre direto? | Acesse `https://SEU-GLPI/plugins/wallpaper/front/wallpaper.php` — se carregar, o problema é só o cache do menu |

## Permissões

Em **Administração → Perfis → (perfil) → Wallpaper**:

| Direito | Permite |
|---|---|
| Ler | ver o painel e as URLs |
| Atualizar | enviar imagem para o canal **piloto** e editar as configurações |
| Promover | promover piloto → produção e enviar imagem direto para **produção** |

Enviar direto para produção exige o direito de promover, porque isso alcança a frota
inteira sem passar pelo piloto.

Na instalação, os perfis que já podem atualizar a Configuração do GLPI recebem os três
direitos; todos os demais ficam **sem acesso**.

> **Ser Super-Admin não concede direitos de plugin por si só.** Cada direito de plugin é
> uma linha explícita em `glpi_profilerights`. Se o botão de promover ou o formulário de
> envio para produção não aparecem, marque o direito que falta na aba de perfil acima e
> salve — o plugin nunca eleva direitos existentes por conta própria.

## Cabeçalhos da resposta

A entrega monta os cabeçalhos explicitamente
([`src/ImageResponse.php`](wallpaper/src/ImageResponse.php)) em vez de delegar ao core,
para que o comportamento seja auditável num lugar só:

| Cabeçalho | Valor | Motivo |
|---|---|---|
| `Content-Type` | `image/jpeg` ou `image/png` | Exato, vindo da lista permitida — nunca refletido da entrada do usuário |
| `X-Content-Type-Options` | `nosniff` | Impede que o conteúdo seja reinterpretado como outro tipo |
| `Content-Disposition` | `inline; filename="wallpaper-<canal>.<ext>"` | Exibição direta, sem forçar download |
| `Cache-Control` | `public, max-age=<TTL>` | Habilita cache na borda e no cliente |
| `ETag` | `"<sha256 do conteúdo>"` | Validador forte para requisições condicionais |
| `Last-Modified` | data do arquivo | Validador alternativo |
| `Content-Length` | tamanho real | Enviado também no `HEAD` |

Outros comportamentos:

- **`HEAD`** devolve os mesmos cabeçalhos sem corpo — a CDN e o próprio Intune podem
  sondar antes de baixar.
- **`If-None-Match` / `If-Modified-Since`** devolvem **304**, com o `If-None-Match` tendo
  precedência (RFC 9110). Validadores fracos (`W/"..."`) são aceitos.
- **Qualquer método diferente de GET/HEAD** devolve **405** com `Allow`.
- **Canal vazio, registro incompleto ou arquivo ausente** devolvem um **404 limpo** —
  nunca um 200 com corpo vazio, que o Windows trataria como imagem inválida.
- Os buffers de saída são descartados antes da escrita, então nenhum warning do PHP
  corrompe os bytes da imagem.
- **Todo cabeçalho que o PHP já tinha registrado é descartado** antes de escrever os
  acima. O GLPI abre a sessão antes de o endpoint ser alcançado, o que deixa um
  `Set-Cookie` e o `Cache-Control` do `session.cache_limiter` na resposta. Os dois
  precisavam sair: cookie em endpoint anônimo é vazamento, e muitas CDNs se recusam a
  cachear uma resposta que carrega cookie — assim como se recusam diante de um segundo
  `Cache-Control` contraditório.

  Isso importa nos **dois** pontos de entrada, por motivos diferentes. No script legado, o
  `header()` usa `replace=true` e já resolveria sozinho o `Cache-Control` duplicado, mas
  nunca remove o cookie. Na rota do Controller quem escreve é o Symfony, cujo
  `Response::sendHeaders()` emite tudo com **`replace=false`** — só o `Content-Type` é
  substituído — então o cabeçalho da sessão sobrevivia *ao lado* do nosso, duplicado.

### Cache de borda (CDN)

O TTL é configurável no painel (padrão **3600s**; `0` desliga). O cache de borda importa
porque este é um endpoint **anônimo** — sem ele, viraria uma fonte barata de carga contra
o GLPI.

⚠️ **Armadilha operacional:** como a URL é fixa, depois de *Promover piloto → produção* a
borda pode continuar servindo a imagem antiga por até o TTL. **Limpe o cache da CDN**
depois de promover, ou use um TTL compatível com a urgência das suas trocas.

## Restrição de rede opcional

**Decisão: o filtro de IP vem DESLIGADO por padrão.**

O raciocínio parte do caso comum — GLPI publicado na internet atrás de uma CDN, atendendo
máquinas cloud-native:

1. **Atrás de uma CDN, o `REMOTE_ADDR` é sempre o IP da borda, nunca o da máquina.** Um
   filtro ingênuo avaliaria a CDN em vez do dispositivo — bloqueando todo mundo ou
   ninguém, mas nunca o que se pretendia.
2. **Máquinas cloud-native conectam de qualquer lugar** — home office, 4G, rede de
   cliente. Restringir por IP quebra justamente os dispositivos que mais dependem do MDM.
3. **A URL é fixa e adivinhável por design.** Isso é exigência da política do Intune, e o
   conteúdo é uma imagem estática feita para aparecer na frota inteira. Aceitável desde
   que o papel de parede não carregue informação sensível.

Se ainda assim precisar restringir, preencha no painel:

| Campo | Efeito |
|---|---|
| `Redes autorizadas` | CIDRs (IPv4/IPv6) ou IPs avulsos. Vazio = qualquer origem liberada |
| `Proxies confiáveis` | **Obrigatório atrás de CDN.** Faixas da CDN (no Azure Front Door, a service tag `AzureFrontDoor.Backend`) |
| `Cabeçalho de IP do cliente` | `X-Forwarded-For` ou `X-Azure-ClientIP` |

O cabeçalho de IP **só é lido quando a conexão vem de um endereço listado em "Proxies
confiáveis"** — nunca de forma crua. Sem essa regra, qualquer cliente poderia forjar o
próprio IP e a restrição seria decorativa. O Azure Front Door preenche o
`X-Azure-ClientIP` com um único endereço, enquanto o `X-Forwarded-For` chega como cadeia.

Salvar redes autorizadas sem declarar nenhum proxy confiável gera um aviso no painel,
porque essa combinação tranca a frota inteira em silêncio.

Todo bloqueio é registrado no log do GLPI com IP e canal — o Intune não reporta o
bloqueio, o papel de parede simplesmente não aplica.

Enquanto a restrição de rede está ativa, a entrega passa a usar `Cache-Control: private,
no-store` — senão um objeto `public` guardado na borda seria servido para qualquer IP sem
nunca consultar a origem, anulando o filtro em silêncio. O custo é o endpoint deixar de
aproveitar o cache de borda, o que é justamente o objetivo: a origem precisa ver cada
requisição para avaliá-la.

Recomendado: implante com a lista vazia, valide o piloto de ponta a ponta e só então
considere restringir.

## Segurança

- O firewall do GLPI 11 libera **apenas** a entrega da imagem
  (`Firewall::STRATEGY_NO_CHECK` para o script legado e `#[SecurityStrategy]` na rota); o
  painel continua exigindo autenticação e direitos.
- O canal é validado contra uma lista fixa (`producao`, `piloto`) e o caminho do arquivo
  deriva dele: não há como fazer directory traversal.
- Os envios são validados pelo conteúdo real do arquivo (`getimagesize`), não pela
  extensão. Só JPEG e PNG são aceitos.
- O `Content-Type` servido é revalidado contra a lista permitida na hora da entrega,
  mesmo já tendo sido validado no envio.
- As imagens ficam fora do docroot, em `files/_plugins/`, guardadas como `.bin` e servidas
  pelo PHP.

## Configuração do Intune

Passo a passo completo — criação das políticas, grupos de piloto/produção, limpeza de
cache da CDN, verificação no dispositivo e tabela de diagnóstico:

📄 **[docs/INTUNE.pt-BR.md](docs/INTUNE.pt-BR.md)**

Em resumo: uma política de **Catálogo de configurações** → categoria **Personalization**
→ **Desktop Image Url** com a URL mostrada no painel, atribuída ao grupo correspondente.

Duas coisas que costumam morder:

- O Personalization CSP é suportado em **Enterprise/Education** e funciona no **Pro**
  apenas com `SetEduPolicies` do
  [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp)
  ou com `BootToCloudPCEnhanced`. O Pro **não** está automaticamente fora, mas exige
  configuração extra. Se a frota for mista, veja a abordagem
  **ADMX + Remediation** na documentação do Intune, que funciona em qualquer edição.
- Definir o papel de parede por essa política **impede o usuário de trocá-lo**.

## Desenvolvimento

Nenhum dos testes exige uma instância do GLPI nem PHP instalado na máquina.

Lógica de CIDR e detecção do IP real do cliente (32 verificações):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/network_filter_test.php
```

Entrega HTTP ponta a ponta com `curl` — cabeçalhos, 304 condicional, `HEAD`, 404 e 405
(29 verificações). Roda o código real do plugin contra stubs mínimos do GLPI:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh tests/endpoint/run.sh
```

Lint:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'find wallpaper -name "*.php" -exec php -l {} \;'
```

## Sobre o idioma

Os comentários do código e a interface administrativa estão em português; a documentação
existe em [inglês](README.md) e português.

## Licença

GPL-3.0-or-later, a mesma do GLPI.
