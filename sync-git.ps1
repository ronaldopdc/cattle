# Script de Sincronização Automática do Git para o Cattle Invest
# Este script monitora a pasta e, a cada alteração de arquivo, adiciona, realiza um commit automático e faz o push para o GitHub.

$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = $PSScriptRoot
$watcher.IncludeSubdirectories = $true
$watcher.EnableRaisingEvents = $true

Write-Host "Monitorando alterações em $PSScriptRoot..." -ForegroundColor Green
Write-Host "Pressione Ctrl+C para encerrar o monitoramento." -ForegroundColor Yellow

$action = {
    $path = $Event.SourceEventArgs.FullPath
    $changeType = $Event.SourceEventArgs.ChangeType
    
    # Ignora pastas do git, arquivos temporários ou de configuração ignorados
    if ($path -like "*\.git*" -or $path -like "*\temp_*" -or $path -like "*\src\config.php" -or $path -like "*.xls" -or $path -like "*.xlsx" -or $path -like "*.doc" -or $path -like "*.docx" -or $path -like "*.sql") {
        return
    }
    
    Write-Host "Alteração detectada: $changeType em $path" -ForegroundColor Cyan
    Start-Sleep -Seconds 2 # Aguarda um instante para evitar conflitos de gravação simultânea
    
    # Executa a sincronização com o Git
    git add .
    git commit -m "Auto-sync: Alterações detectadas em $(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')"
    git push origin main
}

$created = Register-ObjectEvent $watcher "Created" -Action $action
$changed = Register-ObjectEvent $watcher "Changed" -Action $action
$deleted = Register-ObjectEvent $watcher "Deleted" -Action $action

try {
    while ($true) {
        Start-Sleep -Seconds 1
    }
} finally {
    # Limpeza dos eventos ao parar
    Unregister-Event -SourceIdentifier $created.Name
    Unregister-Event -SourceIdentifier $changed.Name
    Unregister-Event -SourceIdentifier $deleted.Name
    $watcher.Dispose()
    Write-Host "Monitoramento encerrado." -ForegroundColor Yellow
}
