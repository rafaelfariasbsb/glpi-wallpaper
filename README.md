# GLPI Wallpaper

Plugin para **GLPI 11** que hospeda imagens de wallpaper em URLs fixas e públicas,
para serem consumidas pela política de papel de parede do **Microsoft Intune**.

O upload é protegido pelos perfis do GLPI; o download é anônimo, porque o Intune
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
| `producao` | frota inteira | `https://SEU-GLPI/plugins/wallpaper/front/image.php?c=producao` |
| `piloto` | grupo de teste | `https://SEU-GLPI/plugins/wallpaper/front/image.php?c=piloto` |

Fluxo pensado para a equipe validar antes de atingir todo mundo:

1. Sobe a imagem nova no canal **piloto**
2. O grupo de teste no Intune já aponta para a URL do piloto — valida na máquina real
3. Clica em **Promover piloto → produção**
4. A URL de produção não muda; a frota recebe a nova imagem na próxima sincronização

Trocar a wallpaper nunca exige mexer na política do Intune.

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
| Atualizar | enviar imagem para o **piloto** e editar a restrição de rede |
| Promover | promover piloto → produção, e enviar imagem direto em **produção** |

Enviar direto para produção exige o direito de promover, porque atinge a frota
inteira sem passar pelo piloto.

Na instalação, perfis que já podem atualizar a Configuração do GLPI recebem os três
direitos; todos os demais ficam **sem acesso**.

## Restrição de acesso por rede (opcional)

Por padrão, a lista de redes autorizadas é **vazia** e qualquer origem baixa a imagem.
Preenchendo-a com CIDRs, apenas as faixas listadas conseguem baixar.

Antes de ligar, saiba:

- **Máquina fora da rede corporativa para de aplicar a wallpaper.** O download parte
  da rede onde o notebook estiver: home office sem VPN sempre ligada, 4G ou rede de
  cliente ficam de fora.
- **A falha é silenciosa.** O Intune não reporta o bloqueio — a wallpaper apenas não
  aplica. Por isso cada bloqueio é registrado no log do GLPI com IP e canal.
- **Atrás de proxy reverso**, preencha também *Proxies confiáveis*. O cabeçalho
  `X-Forwarded-For` só é considerado quando a conexão vem de um endereço cadastrado;
  sem isso qualquer cliente forjaria o próprio IP e a restrição seria decorativa.

Recomendado: subir com a lista vazia, validar o piloto ponta a ponta e só então
restringir — assim, se a wallpaper parar de aplicar, a causa é inequívoca.

## Segurança

- O firewall do GLPI 11 libera **apenas** `front/image.php`
  (`Firewall::STRATEGY_NO_CHECK`); o painel continua exigindo autenticação.
- O canal é validado contra uma lista fixa (`producao`, `piloto`) e o caminho do
  arquivo deriva dele: não há travessia de diretório possível.
- O upload é validado pelo conteúdo real do arquivo (`getimagesize`), não pela
  extensão. Só JPEG e PNG são aceitos.
- As imagens ficam fora do docroot, em `files/_plugins/`.
- A URL é fixa e, portanto, adivinhável — decisão consciente, exigida pela política
  do Intune. É uma imagem destinada a aparecer em toda a frota; se o conteúdo for
  sensível no seu contexto, use a restrição por rede.

## Fora do escopo deste plugin

Criar a política no Intune. Dois pontos que costumam morder:

- A política de papel de parede via CSP exige **Windows Enterprise ou Education**.
  Em licenças **Pro** ela é ignorada silenciosamente.
- A URL precisa ser alcançável pela máquina no momento em que a política é aplicada.

## Desenvolvimento

Testes da lógica de CIDR, sem precisar de GLPI:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/network_filter_test.php
```

## Licença

GPL-3.0-or-later, a mesma do GLPI.
