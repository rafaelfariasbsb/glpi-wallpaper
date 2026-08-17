> 🌍 [English](INTUNE.md) · **Português (Brasil)**

# Configuração do Microsoft Intune

Passo a passo para consumir as imagens hospedadas pelo plugin.

Este documento cobre o lado do **Intune**. Instalar e usar o plugin no GLPI está no
[README](../README.pt-BR.md).

Há duas formas de consumir as imagens. Leia a próxima seção antes de escolher.

---

## Qual abordagem: `DesktopImageUrl` ou ADMX + Remediation

| | **Personalization CSP** (`DesktopImageUrl`) | **ADMX `Desktop\Wallpaper` + Remediation** |
|---|---|---|
| Edições do Windows | só Enterprise / Education / IoT | **Qualquer uma**, inclusive Pro e Home |
| Quem baixa a imagem | o próprio Windows, a partir da URL | um script de Remediation, para um caminho local |
| Partes móveis | uma política | uma política + uma Remediation |
| URL precisa terminar em `.jpg`/`.png` | **Sim** — senão dá `DesktopImageStatus = 4` | Não — o PowerShell não se importa |

**A mistura de edições decide.** Conte a frota antes de escolher — parque misto, com parte
das máquinas em Pro, é comum, e nessas o `DesktopImageUrl` aplica, **reporta sucesso e não
muda nada**, que é o pior tipo de falha. A política ADMX funciona em toda parte, ao custo
de um script para buscar o arquivo.

Dá para contar pelo próprio Intune:

```powershell
Get-MgBetaDeviceManagementManagedDevice -Filter "operatingSystem eq 'Windows'" -All |
    Group-Object skuFamily | Select-Object Count, Name | Sort-Object Count -Descending
```

Tudo que não for Enterprise, Education ou IoT Enterprise não vai honrar o CSP.

Escolha `DesktopImageUrl` quando a frota for uniformemente Enterprise/Education; escolha
ADMX + Remediation quando for mista. As duas consomem as mesmas URLs de canal do plugin, e
nos dois casos trocar o papel de parede é feito no GLPI, nunca no Intune.

Os scripts da segunda abordagem estão em [`intune/`](../intune/) — veja
[ADMX + Remediation](#admx--remediation-qualquer-edição-do-windows) abaixo.

---

## Antes de começar

### 1. Confirme a edição do Windows na frota

O CSP `Personalization` é suportado em:

| Edição | Funciona? |
|---|---|
| Enterprise / Education | ✅ Diretamente |
| IoT Enterprise | ✅ Diretamente |
| **Pro / Pro Education** | ⚠️ Só com `SetEduPolicies` do [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp), ou com `BootToCloudPCEnhanced` |

Se a frota roda Pro sem nenhum dos dois, a política vai aplicar e a imagem não vai mudar —
e o Intune vai reportar sucesso. Resolva isso antes de investir no piloto.

> **Efeito colateral:** definir o papel de parede por essa política **impede o usuário de
> trocá-lo**. É comportamento do CSP, não do plugin.

### 2. Teste as URLs antes de criar a política

De qualquer máquina numa rede que os dispositivos realmente usem:

```bash
curl -I https://SEU-GLPI/plugins/wallpaper/piloto.jpg
```

Você deve ver `HTTP/1.1 200`, `Content-Type: image/jpeg` (ou `image/png`) e
`Cache-Control`. Um `404` significa que o canal ainda não tem imagem — envie uma pelo
painel do GLPI primeiro. Um `302` para a tela de login significa que o firewall do GLPI
não liberou a rota: confira se o plugin está **ativado**.

### 3. Prepare os grupos

| Grupo | Membros | Aponta para |
|---|---|---|
| `Wallpaper - Piloto` | Alguns dispositivos de teste (de preferência de perfis diferentes) | URL do canal `piloto` |
| `Wallpaper - Produção` | A frota | URL do canal `producao` |

Um dispositivo **não pode estar nos dois grupos** — duas políticas dirigindo o mesmo CSP
conflitam e produzem resultado imprevisível. Se usar grupos dinâmicos, exclua
explicitamente os dispositivos do piloto do grupo de produção.

⚠️ **Cuidado com grupo de usuários:** um grupo de usuários arrasta **todos os
dispositivos** de cada membro. Um piloto mirado em "o time de TI" alcança facilmente
várias vezes mais máquinas do que pessoas — e essas máquinas provavelmente também estão no
grupo de produção.

---

## Criando a política (Catálogo de configurações)

Faça isso **duas vezes**: uma para o piloto, outra para produção.

1. Acesse o [centro de administração do Intune](https://intune.microsoft.com) →
   **Dispositivos** → **Configuração** → **Criar** → **Nova política**
2. **Plataforma:** `Windows 10 e posterior`
3. **Tipo de perfil:** `Catálogo de configurações`
4. **Nome:** `Wallpaper - Piloto` (ou `Wallpaper - Produção`)
5. Em **Definições de configuração**, clique em **Adicionar configurações** e procure por
   `Desktop Image Url`
6. Selecione a categoria **Personalization** → marque **Desktop Image Url**
7. Preencha o valor:

   | Política | Valor |
   |---|---|
   | Piloto | `https://SEU-GLPI/plugins/wallpaper/piloto.jpg` |
   | Produção | `https://SEU-GLPI/plugins/wallpaper/producao.jpg` |

   > Use exatamente a URL mostrada no painel do plugin. A extensão precisa estar
   > presente: o Windows classifica o tipo do arquivo pela URL e reporta *Unknown file
   > type* quando não reconhece o alvo.

8. **Atribuições:** atribua ao grupo correspondente
9. **Revisar + criar**

### Alternativa: política personalizada (OMA-URI)

Se preferir um perfil **Personalizado** em vez do Catálogo de configurações:

| Campo | Valor |
|---|---|
| OMA-URI | `./Vendor/MSFT/Personalization/DesktopImageUrl` |
| Tipo de dados | `String` |
| Valor | `https://SEU-GLPI/plugins/wallpaper/producao.jpg` |

Para a tela de bloqueio, o nó equivalente é
`./Vendor/MSFT/Personalization/LockScreenImageUrl`.

---

## Rotina para trocar o papel de parede

Uma vez configurado, trocar a imagem **nunca exige editar a política**:

1. No GLPI: **Plug-ins → Wallpaper**, envie a imagem nova para o canal **piloto**
2. Force uma sincronização num dispositivo do piloto
   (**Configurações → Contas → Acessar trabalho ou escola → Informações → Sincronizar**)
3. Valide o resultado na tela
4. No GLPI, clique em **Promover piloto → produção**
5. **Limpe o cache da CDN** (veja abaixo)
6. A frota pega a imagem nova na próxima sincronização

### Limpando o cache da CDN

Como a URL é fixa, a borda pode continuar servindo a imagem antiga por até o TTL
configurado no plugin (padrão 1 hora). Depois de promover — exemplo com Azure Front Door:

```bash
az afd endpoint purge \
  --resource-group SEU-RG \
  --profile-name SEU-PERFIL-AFD \
  --endpoint-name SEU-ENDPOINT \
  --content-paths '/plugins/wallpaper/producao.jpg'
```

Alternativa: baixar o TTL no painel do plugin. O custo é mais requisições chegando ao
GLPI — o padrão de 1 hora é um meio-termo razoável (recomendado).

---

## ADMX + Remediation (qualquer edição do Windows)

Use quando a frota não for uniformemente Enterprise/Education. Substitui o Personalization
CSP por duas peças que funcionam em **todas** as edições, inclusive Pro e Home:

| Peça | Papel |
|---|---|
| Política de **Catálogo de configurações** com a configuração ADMX `Desktop\Wallpaper` | Aponta o Windows para um **arquivo local** |
| Uma **Remediation** rodando os dois scripts de [`intune/`](../intune/) | Mantém esse arquivo local igual ao canal do GLPI |

A configuração ADMX aceita apenas caminho local ou UNC — nunca uma URL `http` — e é por
isso que alguém precisa colocar o arquivo no disco. Atribua as duas peças ao **mesmo**
grupo.

### Passo 1 — configure os scripts

Os dois scripts começam com o mesmo bloco de configuração. Edite antes de subir; os dois
arquivos precisam concordar:

```powershell
# Canal: 'piloto' ou 'producao'. É a única diferença entre as duas versões.
$Channel = 'piloto'

# URL do GLPI até o parâmetro de canal, inclusive.
$BaseUrl = 'https://SEU-GLPI/plugins/wallpaper/front/image.php?c='

# Pasta local. Precisa ser o MESMO caminho apontado pela política ADMX.
$Dir = 'C:\ProgramData\Wallpaper'
```

A rota legada `image.php?c=` é usada de propósito: o PowerShell não se importa com a
extensão do arquivo, então esse caminho funciona mesmo onde o servidor web não foi
configurado para deixar `/plugins/wallpaper/*.jpg` chegar ao PHP. A URL bonita também
funciona, se você preferir.

Você precisa de **duas** Remediations, uma por canal — idênticas, exceto pelo `$Channel`.

### Passo 2 — crie a política

**Dispositivos → Configuração → Criar → Nova política**, plataforma
`Windows 10 e posterior`, tipo **Catálogo de configurações**. Adicione configurações,
procure por **Desktop Wallpaper** e escolha a que está em
`Modelos Administrativos\Área de Trabalho\Área de Trabalho`:

| Campo | Valor |
|---|---|
| Wallpaper Name | `C:\ProgramData\Wallpaper\wallpaper.jpg` |
| Wallpaper Style | `Fill` |

A configuração é **de usuário** (`./User/Vendor/MSFT/Policy`), então ela mira um grupo de
usuários — não de dispositivos.

> Se o arquivo não existir quando o usuário fizer logon, **nenhum papel de parede é
> exibido**, e o usuário não pode definir o dele, porque essa política também bloqueia
> isso. Todo o passo 3 existe para manter esse arquivo presente e válido.

### Passo 3 — crie a Remediation

**Dispositivos → Correções (Remediations) → Criar pacote de script**:

| Configuração | Valor |
|---|---|
| Arquivo do script de detecção | `intune/detect-wallpaper.ps1` |
| Arquivo do script de correção | `intune/remediate-wallpaper.ps1` |
| Executar com as credenciais do usuário conectado | **Não** — precisa rodar como SYSTEM |
| Impor verificação de assinatura | Não |
| Executar em PowerShell de 64 bits | **Sim** |
| Agendamento | **Diário** (de hora em hora só durante o teste) |

**Por que Remediation e não script de plataforma** (*Dispositivos → Scripts*): um script
de plataforma roda **uma vez por dispositivo** e nunca mais depois de dar certo. Promover
uma imagem nova no GLPI jamais chegaria às máquinas que já rodaram. Uma Remediation roda
em agenda, então publicar no GLPI é suficiente — e ela ainda se autocorrige se alguém
apagar o arquivo.

### O que os scripts fazem

A **detecção** compara o `ETag` do plugin — um sha256 do conteúdo da imagem — com um
arquivo de marca guardado ao lado da imagem, usando uma única requisição `HEAD`. Alguns
bytes por ciclo, sem baixar a imagem. Ela sai com 1 (e dispara a correção) quando o
arquivo está ausente, vazio, não é JPEG/PNG, ou quando o ETag difere.

Se o servidor estiver inalcançável, ela reporta **conforme** de propósito: a imagem que já
está no disco continua boa, e baixar de novo não consertaria um problema de rede. Sem
isso, uma janela de manutenção do GLPI acenderia a frota inteira como falha.

A **correção** baixa para um arquivo temporário, confere se os magic bytes são de JPEG ou
PNG, e só então move para o lugar:

```powershell
Move-Item -LiteralPath $Temp -Destination $Image -Force
```

Escrever direto no destino deixaria todos os dispositivos com um arquivo pela metade se a
conexão caísse — e a política ADMX não exibe papel de parede nenhum quando o arquivo é
inválido. Depois ela grava o ETag, remove a marca do outro canal e concede leitura aos
usuários locais, para que a sessão do usuário consiga exibir a imagem.

### Regras de atribuição que importam

- **Um dispositivo, um canal.** Os dois canais gravam no mesmo `wallpaper.jpg`. Um
  dispositivo que receba a Remediation do piloto *e* a de produção fica alternando entre
  as imagens conforme a ordem de execução. Mantenha os grupos mutuamente exclusivos — e
  atenção à armadilha: se o piloto mira um grupo de **usuários** e a produção um grupo de
  **dispositivos**, a sobreposição fica invisível até você comparar máquina por máquina.
- **A imagem aparece no próximo logon**, não na hora. A política ADMX é aplicada quando a
  sessão inicia.
- Um grupo de usuários arrasta **todos os dispositivos** de cada membro.

### Peculiaridades conhecidas da API e do portal

| Peculiaridade | O que fazer |
|---|---|
| `runRemediationScript` volta `false` do Graph mesmo enviado como `true` | Ignore. Versões recentes do Intune removeram esse botão da interface; o script de correção roda sempre que a detecção sai com 1 e existe um script de correção. Confirme pelos `deviceRunStates`, não pelo campo |
| A atribuição só aceita a action `assign` | `POST /assignments` e `PATCH` num assignment devolvem *"No OData route exists"* |
| Definir agenda substitui a atribuição inteira | Envie `target`, `runRemediationScript` e `runSchedule` juntos todas as vezes |

### Opcional: criar pela Graph API

Útil para reproduzir a configuração em vários tenants, ou para manter os scripts no git
como fonte única da verdade:

```powershell
Connect-MgGraph -Scopes 'DeviceManagementConfiguration.ReadWrite.All'

$body = @{
    displayName              = 'Wallpaper - canal producao'
    description              = 'Mantem a imagem local igual ao canal do GLPI.'
    publisher                = 'TI'
    runAs32Bit               = $false
    runAsAccount             = 'system'
    enforceSignatureCheck    = $false
    detectionScriptContent   = [Convert]::ToBase64String([IO.File]::ReadAllBytes('intune/detect-wallpaper.ps1'))
    remediationScriptContent = [Convert]::ToBase64String([IO.File]::ReadAllBytes('intune/remediate-wallpaper.ps1'))
} | ConvertTo-Json

$script = Invoke-MgGraphRequest -Method POST `
    -Uri 'https://graph.microsoft.com/beta/deviceManagement/deviceHealthScripts' -Body $body

# Atribui, com agenda diaria
$assign = @{
    deviceHealthScriptAssignments = @(@{
        target = @{
            '@odata.type' = '#microsoft.graph.groupAssignmentTarget'
            groupId       = '<ID-DO-GRUPO>'
        }
        runRemediationScript = $true
        runSchedule = @{
            '@odata.type' = '#microsoft.graph.deviceHealthScriptDailySchedule'
            interval      = 1
            time          = '10:00:00'
            useUtc        = $false
        }
    })
} | ConvertTo-Json -Depth 6

Invoke-MgGraphRequest -Method POST -Body $assign `
    -Uri "https://graph.microsoft.com/beta/deviceManagement/deviceHealthScripts/$($script.id)/assign"
```

Verifique o resultado pelos run states, não pelo objeto de atribuição:

```powershell
Invoke-MgGraphRequest -Method GET -Uri (
    'https://graph.microsoft.com/beta/deviceManagement/deviceHealthScripts/' +
    "$($script.id)/deviceRunStates?`$expand=managedDevice(`$select=deviceName)")
```

Uma primeira execução saudável lê: detecção `fail` com *"imagem ausente"*, correção
`success`, e a detecção pós-correção reportando *"em dia"* com o mesmo ETag que o servidor
publica.

---

## Verificação e diagnóstico

### Status no próprio dispositivo

O CSP expõe o resultado do download pelo `DesktopImageStatus`:

| Código | Significado | O que fazer |
|---|---|---|
| `1` | Baixado com sucesso | Nada — funcionou |
| `2` | Download em andamento | Espere e verifique de novo |
| `3` | Download falhou | O dispositivo não alcança a URL: rede, DNS, TLS ou CDN |
| `4` | **Unknown file type** | A URL não termina em extensão de imagem, ou o `Content-Type` está errado |
| `5` | Esquema de URL não suportado | Use `https://` |
| `6` | Máximo de tentativas excedido | Instabilidade de rede ou endpoint fora |

Consulte no dispositivo (PowerShell como administrador):

```powershell
Get-ChildItem -Path 'HKLM:\SOFTWARE\Microsoft\PolicyManager\current\device\Personalization'
```

### A imagem baixada

O Windows guarda a cópia local em:

```
C:\ProgramData\Microsoft\Windows\Personalization\Desktop
```

Se o arquivo está lá e correto mas a tela não mudou, o problema é a edição do Windows
(veja o item 1) ou outra política sobrepondo.

### Do lado do GLPI

Se você ativou a restrição de rede no plugin, todo bloqueio aparece em
**Administração → Log**, com IP e canal. Lembre que **atrás de uma CDN o IP observado é o
da borda**, não o do dispositivo — veja a seção correspondente no
[README](../README.pt-BR.md#restrição-de-rede-opcional).

---

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Política reporta sucesso, papel de parede não muda | Windows Pro sem `SetEduPolicies` |
| `DesktopImageStatus = 4` | URL sem extensão de imagem — use a URL mostrada no painel |
| `DesktopImageStatus = 3` | O dispositivo não alcança o GLPI; teste com `curl -I` da rede dele |
| Imagem antiga persiste depois de promover | Cache da CDN — limpe |
| Funciona no piloto, não em produção | O dispositivo está nos dois grupos, com políticas conflitantes |
| `404` na URL | O canal ainda não tem imagem no painel do GLPI |
| Redireciona para a tela de login | Plugin desativado no GLPI |
| Com ADMX: fundo sólido, sem papel de parede | O arquivo local não existe ou está corrompido — veja os `deviceRunStates` da Remediation |

---

## Referências

- [Personalization CSP](https://learn.microsoft.com/windows/client-management/mdm/personalization-csp)
- [Configurar os fundos da área de trabalho e da tela de bloqueio](https://learn.microsoft.com/windows/configuration/background/)
- [Restrições de dispositivo Windows no Intune](https://learn.microsoft.com/intune/device-configuration/templates/ref-device-restrictions-windows#personalization)
- [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp)
- [Correções (Remediations) no Intune](https://learn.microsoft.com/intune/intune-service/fundamentals/remediations)
