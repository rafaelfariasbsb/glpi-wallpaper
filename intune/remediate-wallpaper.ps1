<#
    Remediacao da Remediation "wallpaper" do Intune.

    Baixa a imagem do canal e a grava em C:\ProgramData\Wallpaper, que e o
    caminho apontado pela politica ADMX (Desktop\Wallpaper).

    Escreve em arquivo temporario e so promove ao destino depois de validar o
    conteudo. Sobrescrever o destino direto deixaria a frota inteira com um
    arquivo pela metade caso a conexao caisse no meio do download — e a ADMX nao
    exibe wallpaper nenhuma quando o arquivo nao presta.

    Para a versao de PRODUCAO, troque apenas a linha $Channel abaixo.

    @license GPL-3.0-or-later
#>

$Channel = 'piloto'

$BaseUrl = 'https://YOUR-GLPI/plugins/wallpaper/front/image.php?c='
$Dir     = 'C:\ProgramData\Wallpaper'
$Image   = Join-Path $Dir 'wallpaper.jpg'
$Stamp   = Join-Path $Dir "$Channel.etag"
$Temp    = Join-Path $Dir "wallpaper.$Channel.tmp"

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

try {
    New-Item -ItemType Directory -Force -Path $Dir | Out-Null

    $response = Invoke-WebRequest -Uri "$BaseUrl$Channel" -OutFile $Temp -PassThru `
                                  -UseBasicParsing -TimeoutSec 120

    if (-not (Test-Path -LiteralPath $Temp) -or (Get-Item -LiteralPath $Temp).Length -eq 0) {
        throw 'download vazio'
    }

    $magic = [byte[]]::new(2)
    $fs = [IO.File]::OpenRead($Temp)
    try { $null = $fs.Read($magic, 0, 2) } finally { $fs.Dispose() }
    $isJpeg = $magic[0] -eq 0xFF -and $magic[1] -eq 0xD8
    $isPng  = $magic[0] -eq 0x89 -and $magic[1] -eq 0x50
    if (-not ($isJpeg -or $isPng)) {
        throw 'o conteudo baixado nao e JPEG nem PNG'
    }

    Move-Item -LiteralPath $Temp -Destination $Image -Force

    # A marca so e gravada depois da imagem estar no lugar: se algo falhar no
    # meio, a deteccao continua acusando desatualizado e tenta de novo.
    $etag = ($response.Headers['ETag'] | Select-Object -First 1)
    if (-not [string]::IsNullOrWhiteSpace($etag)) {
        Set-Content -LiteralPath $Stamp -Value $etag.Trim().Trim('"') -NoNewline -Encoding ascii
    } elseif (Test-Path -LiteralPath $Stamp) {
        Remove-Item -LiteralPath $Stamp -Force
    }

    # A marca do outro canal deixaria a deteccao dele achando que esta em dia.
    $outro = if ($Channel -eq 'piloto') { 'producao' } else { 'piloto' }
    $marcaOutro = Join-Path $Dir "$outro.etag"
    if (Test-Path -LiteralPath $marcaOutro) {
        Remove-Item -LiteralPath $marcaOutro -Force
    }

    # O script roda como SYSTEM; quem exibe a imagem e a sessao do usuario, que
    # precisa conseguir le-la.
    icacls $Dir /grant '*S-1-1-0:(OI)(CI)R' | Out-Null

    $size = (Get-Item -LiteralPath $Image).Length
    Write-Output "wallpaper do canal $Channel atualizada ($size bytes)"
    exit 0
}
catch {
    if (Test-Path -LiteralPath $Temp) {
        Remove-Item -LiteralPath $Temp -Force -ErrorAction SilentlyContinue
    }
    Write-Output "falha ao atualizar a wallpaper: $($_.Exception.Message)"
    exit 1
}
