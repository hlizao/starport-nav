# 星港导航 · 保活检查
# 由计划任务 Starport-NavKeepAlive 每 5 分钟调用；也可手动运行
$ErrorActionPreference = 'SilentlyContinue'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$Port = 8931

# 检查 1：本地 HTTP 服务是否在线（探测回环端口）
$serverOk = $false
try {
  $client = New-Object Net.Sockets.TcpClient
  $client.Connect('127.0.0.1', $Port)
  $serverOk = $client.Connected
  $client.Close()
} catch { $serverOk = $false }

# 检查 2：Cloudflare 隧道进程是否存活（仅匹配本部署的特征）
$cfOk = $false
Get-CimInstance Win32_Process -Filter "Name='cloudflared.exe'" -ErrorAction SilentlyContinue |
  Where-Object { $_.CommandLine -like "*127.0.0.1:$Port*" } |
  ForEach-Object { $cfOk = $true }

if ($serverOk -and $cfOk) {
  Write-Host "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') 导航站服务正常，无需处理"
  exit 0
}

Write-Host "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') 检测到服务异常（本地服务=$serverOk 隧道=$cfOk），自动重新部署..."
& (Join-Path $Root 'start-nav.ps1') -NoBrowser
