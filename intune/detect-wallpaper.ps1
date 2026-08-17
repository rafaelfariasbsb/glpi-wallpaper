<#
    Deteccao da Remediation "wallpaper" do Intune.

    Sai com 1 (nao conforme -> dispara a remediacao) quando a imagem local esta
    ausente, vazia, corrompida ou desatualizada em relacao ao canal do GLPI.
    Sai com 0 quando ja esta em dia.

    A comparacao usa o ETag que o plugin publica: sha256 do conteudo, calculado
    no upload. Uma requisicao HEAD por ciclo, alguns bytes, sem baixar a imagem —
    barato o suficiente para rodar de hora em hora na frota inteira.

    Para a versao de PRODUCAO, troque apenas a linha $Channel abaixo.

    @license GPL-3.0-or-later
#>

$Channel = 'piloto'

$BaseUrl = 'https://YOUR-GLPI/plugins/wallpaper/front/image.php?c='
$Dir     = 'C:\ProgramData\Wallpaper'
$Image   = Join-Path $Dir 'wallpaper.jpg'
$Stamp   = Join-Path $Dir "$Channel.etag"

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

try {
    if (-not (Test-Path -LiteralPath $Image)) {
        Write-Output "imagem ausente em $Image"
        exit 1
    }

    if ((Get-Item -LiteralPath $Image).Length -eq 0) {
        Write-Output 'imagem local vazia'
        exit 1
    }

    # Um download interrompido deixa um arquivo que existe e tem tamanho, mas nao
    # e uma imagem — e a ADMX aplicaria um fundo quebrado sem reclamar.
    $magic = [byte[]]::new(2)
    $fs = [IO.File]::OpenRead($Image)
    try { $null = $fs.Read($magic, 0, 2) } finally { $fs.Dispose() }
    $isJpeg = $magic[0] -eq 0xFF -and $magic[1] -eq 0xD8
    $isPng  = $magic[0] -eq 0x89 -and $magic[1] -eq 0x50
    if (-not ($isJpeg -or $isPng)) {
        Write-Output 'imagem local nao e JPEG nem PNG'
        exit 1
    }

    if (-not (Test-Path -LiteralPath $Stamp)) {
        # Sem marca: ou e a primeira execucao, ou a imagem veio do outro canal.
        Write-Output 'sem marca de versao para este canal'
        exit 1
    }

    $local = (Get-Content -LiteralPath $Stamp -Raw).Trim()

    $head = Invoke-WebRequest -Uri "$BaseUrl$Channel" -Method Head -UseBasicParsing -TimeoutSec 60
    $remote = ($head.Headers['ETag'] | Select-Object -First 1)
    if ([string]::IsNullOrWhiteSpace($remote)) {
        # Sem ETag nao ha o que comparar. Nao forcamos download: melhor manter a
        # imagem atual do que baixar 3 MB por device a cada ciclo.
        Write-Output 'servidor nao enviou ETag; mantendo a imagem atual'
        exit 0
    }
    $remote = $remote.Trim().Trim('"')

    if ($local -ne $remote) {
        Write-Output "imagem desatualizada (local $local != remoto $remote)"
        exit 1
    }

    Write-Output "em dia ($local)"
    exit 0
}
catch {
    # O GLPI pode estar fora, ou a maquina sem rede. Disparar a remediacao aqui
    # so trocaria um erro por outro: a imagem que ja esta no disco continua boa.
    Write-Output "falha na verificacao: $($_.Exception.Message)"
    exit 0
}
