# Configuração no Microsoft Intune

Guia passo a passo para consumir as imagens hospedadas pelo plugin.

Este documento cobre a parte do **Intune**. A instalação e o uso do plugin no GLPI
estão no [README](../README.md).

---

## Antes de começar

### 1. Confirme a edição do Windows da frota

O `Personalization` CSP é suportado em:

| Edição | Funciona? |
|---|---|
| Enterprise / Education | ✅ Direto |
| IoT Enterprise | ✅ Direto |
| **Pro / Pro Education** | ⚠️ Só com `SetEduPolicies` do [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp) definido, ou com `BootToCloudPCEnhanced` |

Se a frota for Pro sem nenhuma dessas configurações, a política será aplicada mas a
imagem não trocará — e o Intune reportará sucesso. Resolva isso antes de investir no
piloto.

> **Efeito colateral:** definir a wallpaper por esta política **impede o usuário de
> trocar o papel de parede**. É comportamento do CSP, não do plugin.

### 2. Teste as URLs antes de criar a política

De uma máquina qualquer da rede que os devices usam:

```bash
curl -I https://SEU-GLPI/plugins/wallpaper/piloto.jpg
```

Você deve ver `HTTP/1.1 200`, `Content-Type: image/jpeg` (ou `image/png`) e
`Cache-Control`. Se vier `404`, o canal ainda não tem imagem — suba uma pelo painel do
GLPI primeiro. Se vier `302` para uma tela de login, o firewall do GLPI não liberou a
rota: confira se o plugin está **ativado**.

### 3. Prepare os grupos

| Grupo | Conteúdo | Aponta para |
|---|---|---|
| `Wallpaper - Piloto` | Alguns devices de teste (idealmente de perfis variados) | URL do canal `piloto` |
| `Wallpaper - Produção` | A frota | URL do canal `producao` |

Um device **não deve estar nos dois grupos** — duas políticas do mesmo CSP em conflito
geram resultado imprevisível. Se usar grupos dinâmicos, exclua explicitamente os
devices do piloto do grupo de produção.

---

## Criando a política (Settings catalog)

Faça isto **duas vezes**: uma para o piloto, outra para produção.

1. Acesse o [Intune admin center](https://intune.microsoft.com) →
   **Devices** → **Configuration** → **Create** → **New Policy**
2. **Platform:** `Windows 10 and later`
3. **Profile type:** `Settings catalog`
4. **Name:** `Wallpaper - Piloto` (ou `Wallpaper - Produção`)
5. Em **Configuration settings**, clique em **Add settings** e busque por
   `Desktop Image Url`
6. Selecione a categoria **Personalization** → marque **Desktop Image Url**
7. Preencha o valor:

   | Política | Valor |
   |---|---|
   | Piloto | `https://SEU-GLPI/plugins/wallpaper/piloto.jpg` |
   | Produção | `https://SEU-GLPI/plugins/wallpaper/producao.jpg` |

   > Use exatamente a URL que o painel do plugin exibe. A extensão precisa estar
   > presente: o Windows classifica o tipo do arquivo pela URL e reporta
   > *Unknown file type* quando não a reconhece.

8. **Assignments:** atribua ao grupo correspondente
9. **Review + create**

### Alternativa: política customizada (OMA-URI)

Se preferir um perfil **Custom** em vez do Settings catalog:

| Campo | Valor |
|---|---|
| OMA-URI | `./Vendor/MSFT/Personalization/DesktopImageUrl` |
| Data type | `String` |
| Value | `https://SEU-GLPI/plugins/wallpaper/producao.jpg` |

Para a tela de bloqueio, o nó equivalente é
`./Vendor/MSFT/Personalization/LockScreenImageUrl`.

---

## Rotina de troca da wallpaper

Depois de configurado, trocar a imagem **nunca exige editar a política**:

1. No GLPI: **Plugins → Wallpaper**, envie a imagem nova no canal **piloto**
2. Force sincronização num device do piloto
   (**Configurações → Contas → Acessar trabalho ou escola → Info → Sincronizar**)
3. Valide o resultado na tela
4. No GLPI, clique em **Promover piloto → produção**
5. **Faça purge do cache no Azure Front Door** (veja abaixo)
6. A frota recebe a nova imagem na próxima sincronização

### Purge do cache no Front Door

Como a URL é fixa, a borda pode continuar servindo a imagem antiga por até o TTL
configurado no plugin (padrão 1 hora). Após promover:

```bash
az afd endpoint purge \
  --resource-group SEU-RG \
  --profile-name SEU-PERFIL-AFD \
  --endpoint-name SEU-ENDPOINT \
  --content-paths '/plugins/wallpaper/producao.jpg'
```

Alternativa: reduzir o TTL no painel do plugin. O custo é mais requisições chegando
ao GLPI — o padrão de 1 hora é um meio-termo razoável (sugerido).

---

## Verificação e diagnóstico

### Status no próprio device

O CSP expõe o resultado do download em `DesktopImageStatus`:

| Código | Significado | O que fazer |
|---|---|---|
| `1` | Baixado com sucesso | Nada — funcionou |
| `2` | Download em andamento | Aguardar e reconsultar |
| `3` | Falha no download | Device não alcança a URL: rede, DNS, TLS ou Front Door |
| `4` | **Tipo de arquivo desconhecido** | A URL não termina em extensão de imagem, ou o `Content-Type` está errado |
| `5` | Esquema de URL não suportado | Use `https://` |
| `6` | Falha após retentativas | Instabilidade de rede ou endpoint fora do ar |

Consulte no device (PowerShell como administrador):

```powershell
Get-ChildItem -Path 'HKLM:\SOFTWARE\Microsoft\PolicyManager\current\device\Personalization'
```

### A imagem baixada

O Windows guarda a cópia local em:

```
C:\ProgramData\Microsoft\Windows\Personalization\Desktop
```

Se o arquivo está lá e correto mas a tela não mudou, o problema é edição do Windows
(veja o item 1) ou outra política sobrepondo.

### Do lado do GLPI

Se você ativou a restrição por rede no plugin, cada bloqueio aparece em
**Administração → Log**, com IP e canal. Lembre que **atrás do Front Door o IP visto é
o da borda**, não o do device — veja a seção correspondente no
[README](../README.md#restrição-de-acesso-por-rede-opcional).

---

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| Política reporta sucesso, wallpaper não muda | Windows Pro sem `SetEduPolicies` |
| `DesktopImageStatus = 4` | URL sem extensão de imagem — use a URL que o painel exibe |
| `DesktopImageStatus = 3` | Device não alcança o GLPI; teste com `curl -I` da rede do device |
| Imagem antiga persiste após promover | Cache do Front Door — faça purge |
| Funciona no piloto, não em produção | Device está nos dois grupos, com políticas em conflito |
| `404` na URL | Canal ainda sem imagem no painel do GLPI |
| Redirect para tela de login | Plugin desativado no GLPI |

---

## Referências

- [Personalization CSP](https://learn.microsoft.com/windows/client-management/mdm/personalization-csp)
- [Configure the desktop and lock screen backgrounds](https://learn.microsoft.com/windows/configuration/background/)
- [Windows device restrictions no Intune](https://learn.microsoft.com/intune/device-configuration/templates/ref-device-restrictions-windows#personalization)
- [SharedPC CSP](https://learn.microsoft.com/windows/client-management/mdm/sharedpc-csp)
