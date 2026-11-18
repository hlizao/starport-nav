# ================================================
#  星港导航 · 一键部署（本地服务 + 公网隧道）
#  用法：双击「启动导航站.bat」，或右键本文件选择"使用 PowerShell 运行"
# ================================================
param([switch]$NoBrowser)
$ErrorActionPreference = 'Stop'
$Root      = Split-Path -Parent $MyInvocation.MyCommand.Path
$DeployDir = Join-Path $Root 'nav-deploy'
$Tool      = Join-Path $Root '.temp\tools\cloudflared.exe'
$UrlFile   = Join-Path $Root '导航站公网地址.txt'
$CfLog     = Join-Path $Root '.temp\cloudflared-latest.log'
$Port      = 8931

if (-not (Test-Path (Join-Path $DeployDir 'index.html'))) { Write-Host '未找到 nav-deploy\index.html，请先确认目录'; exit 1 }
if (-not (Test-Path $Tool)) { Write-Host '未找到隧道工具，请确认 .temp\tools\cloudflared.exe 存在'; exit 1 }

# 1) 停止旧实例（仅匹配本部署的特征进程，不影响其他程序）
Get-CimInstance Win32_Process -Filter "Name='python.exe'" |
  Where-Object { $_.CommandLine -like '*http.server*' -and $_.CommandLine -like "*$Port*" } |
  ForEach-Object { taskkill.exe /PID $_.ProcessId /T /F 2>$null | Out-Null }
Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" |
  Where-Object { $_.CommandLine -like "*127.0.0.1:$Port*" } |
  ForEach-Object { taskkill.exe /PID $_.ProcessId /T /F 2>$null | Out-Null }
Start-Sleep -Seconds 1

# 2) 启动本地服务（只服务 nav-deploy 目录，仅绑定本机回环地址）
Start-Process -FilePath 'python' -ArgumentList @('-m','http.server',"$Port",'--bind','127.0.0.1','--directory',('"{0}"' -f $DeployDir)) -WindowStyle Hidden

# 3) 启动 Cloudflare 免注册隧道（日志写入 .temp\cloudflared-latest.log）
Remove-Item $CfLog -Force -ErrorAction SilentlyContinue
Remove-Item ($CfLog + '.out') -Force -ErrorAction SilentlyContinue
$cmdArgs = '/c {0} tunnel --url http://127.0.0.1:{1} --no-autoupdate --protocol http2 > "{2}" 2>&1' -f $Tool, $Port, $CfLog
Start-Process -FilePath $env:ComSpec -ArgumentList $cmdArgs -WindowStyle Hidden

# 4) 等待隧道分配公网地址（最多约 50 秒）
$url = $null
for ($i = 0; $i -lt 25; $i++) {
  Start-Sleep -Seconds 2
  if (Test-Path $CfLog) {
    $u = Select-String -Path $CfLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' -AllMatches |
         ForEach-Object { $_.Matches.Value } | Select-Object -First 1
    if ($u) { $url = $u; break }
  }
}

if ($url) {
  $content = "星港导航 公网地址`r`n$url`r`n更新时间：$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')`r`n说明：云电脑或服务重启后地址会变化，重新运行「启动导航站.bat」即可，新地址自动更新到本文件。`r`n"
  [IO.File]::WriteAllText($UrlFile, $content, (New-Object Text.UTF8Encoding $true))
  Write-Host '=============================================='
  Write-Host '  部署完成！公网地址：'
  Write-Host "  $url"
  Write-Host '  地址已保存到：导航站公网地址.txt'
  Write-Host '=============================================='
  if (-not $NoBrowser) { Start-Process $url }
  exit 0
} else {
  Write-Host "隧道未在预期时间内就绪，请稍后重试或检查日志：$CfLog"
  exit 1
}
