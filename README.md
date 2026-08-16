# GLPI Wallpaper

Plugin para **GLPI 11** que hospeda imagens de wallpaper em URLs fixas e públicas,
para serem consumidas pela política `Personalization/DesktopImageUrl` do
**Microsoft Intune**.

O upload é protegido pelos perfis do GLPI; o download é anônimo, porque o Windows
baixa a imagem no contexto `SYSTEM` da máquina, sem sessão nem cookie.

## Por que existe

Não há plugin equivalente no ecossistema GLPI. O
[Branding](https://help.glpi-project.org/doc-plugins/plugin-glpi-network/branding)
só troca o fundo da tela de login do próprio GLPI, e o
[phonebg](https://github.com/monta990/phonebg) gera wallpapers de telefones a partir
de dados do inventário. Nenhum dos dois serve uma imagem para MDM consumir.

## Como funciona

Dois canais fixos, cada um com uma URL que **nunca muda**:

| Canal | Uso | URL |
|---|---|---|
| `producao` | frota inteira | `https://SEU-GLPI/plugins/wallpaper/producao.jpg` |
| `piloto` | grupo de teste | `https://SEU-GLPI/plugins/wallpaper/piloto.jpg` |

Fluxo pensado para a equipe validar antes de atingir todo mundo:

1. Sobe a imagem nova no canal **piloto**
2. O grupo de teste no Intune já aponta para a URL do piloto — valida na máquina real
3. Clica em **Promover piloto → produção**
4. A URL de produção não muda; a frota recebe a nova imagem na próxima sincronização

Trocar a wallpaper nunca exige mexer na política do Intune.

### Por que a URL termina em `.jpg`

O Personalization CSP **classifica o tipo do arquivo** e expõe o resultado em
`DesktopImageStatus`, onde o código **`4` significa "Unknown file type"**. A
documentação da Microsoft descreve o valor sempre como *"an http or https URL to a
jpg, jpeg or png image"*, com exemplos que trazem a extensão explícita.

Por isso a URL pública é uma rota nativa do GLPI 11
([`src/Controller/ImageController.php`](wallpaper/src/Controller/ImageController.php))
terminada em extensão de imagem, em vez de um `image.php?c=producao`.

A extensão pedida **não decide o que é servido** — o `Content-Type` vem sempre do
arquivo real. Se você trocar a imagem de PNG para JPEG, a URL antiga continua
respondendo e a política do Intune não quebra; o painel apenas avisa da divergência
para você alinhar quando puder.

A rota antiga segue disponível para diagnóstico:
`https://SEU-GLPI/plugins/wallpaper/front/image.php?c=producao`

## Instalação

```bash
cd /var/www/glpi/plugins
git clone https://github.com/rafaelfariasbsb/glpi-wallpaper.git
mv glpi-wallpaper/wallpaper .    # o diretório do plugin precisa se chamar "wallpaper"
chown -R www-data:www-data wallpaper
```

Depois, em **Configurar → Plugins**, instale e ative o *Wallpaper*.

O plugin grava as imagens em `files/_plugins/wallpaper/`, criado na instalação.
Nunca escreve no próprio diretório de código.

## Permissões

Em **Administração → Perfis → (perfil) → Wallpaper**:

| Direito | Permite |
|---|---|
| Ler | ver o painel e as URLs |
| Atualizar | enviar imagem para o **piloto** e editar as configurações |
| Promover | promover piloto → produção, e enviar imagem direto em **produção** |

Enviar direto para produção exige o direito de promover, porque atinge a frota
inteira sem passar pelo piloto.

Na instalação, perfis que já podem atualizar a Configuração do GLPI recebem os três
direitos; todos os demais ficam **sem acesso**.

## Cabeçalhos da resposta

A entrega monta os cabeçalhos manualmente
([`src/ImageResponse.php`](wallpaper/src/ImageResponse.php)), em vez de delegar ao
core, para que o comportamento seja auditável aqui:

| Cabeçalho | Valor | Por quê |
|---|---|---|
| `Content-Type` | `image/jpeg` ou `image/png` | Exato, vindo da allowlist — nunca refletido de entrada do usuário |
| `X-Content-Type-Options` | `nosniff` | Impede reinterpretação do conteúdo como outro tipo |
| `Content-Disposition` | `inline; filename="wallpaper-<canal>.<ext>"` | Exibição direta, sem download forçado |
| `Cache-Control` | `public, max-age=<TTL>` | Permite cache na borda e no cliente |
| `ETag` | `"<sha256 do conteúdo>"` | Validador forte para requisições condicionais |
| `Last-Modified` | data do arquivo | Validador alternativo |
| `Content-Length` | tamanho real | Enviado também em `HEAD` |

Outros comportamentos:

- **`HEAD`** responde os mesmos cabeçalhos, sem corpo — o Front Door e o próprio
  Intune podem sondar antes de baixar.
- **`If-None-Match` / `If-Modified-Since`** respondem **304**, com `If-None-Match`
  tendo precedência (RFC 9110). Validadores fracos (`W/"..."`) são aceitos.
- **Método diferente de GET/HEAD** responde **405** com `Allow`.
- **Canal sem imagem, registro incompleto ou arquivo ausente** responde **404 limpo** —
  nunca 200 com corpo vazio, que o Windows trataria como imagem inválida.
- Buffers de saída são descartados antes de escrever, para que nenhum warning do PHP
  corrompa os bytes da imagem.

### Cache e o Azure Front Door

O TTL é configurável no painel (padrão **3600s**; `0` desativa). Cachear na borda
importa porque este é um endpoint **anônimo** — sem cache, ele seria uma fonte barata
de carga contra o GLPI.

⚠️ **Armadilha operacional:** como a URL é fixa, depois de *Promover piloto → produção*
a borda pode continuar servindo a imagem antiga por até o TTL. Faça **purge do cache
no Front Door** após promover, ou use um TTL compatível com a urgência da troca.

## Restrição de acesso por rede (opcional)

**Decisão: o filtro de IP vem DESLIGADO por padrão.**

O motivo é o cenário real deste plugin — GLPI publicado na internet atrás do
**Azure Front Door**, servindo máquinas cloud-native:

1. **Atrás de um CDN, `REMOTE_ADDR` é sempre o IP da borda, nunca o da máquina.**
   Um filtro ingênuo avaliaria o Front Door, não o device — bloqueando todo mundo ou
   ninguém, mas nunca o que se pretendia.
2. **Máquinas cloud-native saem de qualquer rede** — home office, 4G, rede de cliente.
   Restringir por IP quebra exatamente os dispositivos que mais dependem do MDM.
3. **A URL é fixa e adivinhável por design.** É uma exigência da política do Intune, e
   o conteúdo é uma imagem estática destinada a aparecer em toda a frota. Aceitável
   quando a wallpaper não carrega informação sensível.

Se ainda assim você precisar restringir, preencha no painel:

| Campo | Efeito |
|---|---|
| `Redes autorizadas` | CIDRs (IPv4/IPv6) ou IPs avulsos. Vazio = libera qualquer origem |
| `Proxies confiáveis` | **Obrigatório atrás de CDN.** Ranges do Azure Front Door (service tag `AzureFrontDoor.Backend`) |
| `Cabeçalho de IP real` | `X-Forwarded-For` ou `X-Azure-ClientIP` |

O cabeçalho de IP **só é lido quando a conexão vem de um endereço listado em
"Proxies confiáveis"** — nunca cru. Sem essa regra, qualquer cliente forjaria o
próprio IP e a restrição seria decorativa. O Front Door preenche `X-Azure-ClientIP`
com um único endereço, enquanto o `X-Forwarded-For` chega como cadeia.

Salvar redes autorizadas sem declarar proxies confiáveis emite um aviso no painel,
porque é a combinação que silenciosamente bloqueia a frota inteira.

Cada bloqueio é registrado no log do GLPI com IP e canal — o Intune não reporta o
bloqueio, a wallpaper apenas não aplica.

Recomendado: subir com a lista vazia, validar o piloto ponta a ponta e só então
considerar restringir.

## Segurança

- O firewall do GLPI 11 libera **apenas** a entrega da imagem
  (`Firewall::STRATEGY_NO_CHECK` no script legado e `#[SecurityStrategy]` na rota);
  o painel continua exigindo autenticação e permissão.
- O canal é validado contra uma lista fixa (`producao`, `piloto`) e o caminho do
  arquivo deriva dele: não há travessia de diretório possível.
- O upload é validado pelo conteúdo real do arquivo (`getimagesize`), não pela
  extensão. Só JPEG e PNG são aceitos.
- O `Content-Type` servido é revalidado contra a allowlist na hora da entrega, mesmo
  já tendo sido validado no upload.
- As imagens ficam fora do docroot, em `files/_plugins/`, gravadas como `.bin` e
  servidas pelo PHP.

## Configuração no Intune

Passo a passo completo — criação das políticas, grupos piloto/produção, purge do
Front Door, verificação no device e tabela de problemas comuns:

📄 **[docs/INTUNE.md](docs/INTUNE.md)**

Resumo: política do **Settings catalog** → categoria **Personalization** →
**Desktop Image Url** com a URL que o painel exibe, atribuída ao grupo
correspondente.

Dois pontos que costumam morder:

- O Personalization CSP é suportado em **Enterprise/Education** e funciona em **Pro**
  apenas com `SetEduPolicies` do
  [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp)
  ou `BootToCloudPCEnhanced`. Pro **não** está automaticamente fora, mas exige
  configuração adicional.
- Definir a wallpaper por esta política **impede o usuário de trocá-la**.

## Desenvolvimento

Nenhum dos testes exige uma instância GLPI nem PHP instalado na máquina.

Lógica de CIDR e detecção de IP real (32 asserções):

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/network_filter_test.php
```

Entrega HTTP ponta a ponta com `curl` — headers, 304 condicional, `HEAD`, 404 e 405
(24 asserções). Sobe o código real do plugin sobre stubs mínimos do GLPI:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh tests/endpoint/run.sh
```

Lint:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'find wallpaper -name "*.php" -exec php -l {} \;'
```

## Licença

GPL-3.0-or-later, a mesma do GLPI.
