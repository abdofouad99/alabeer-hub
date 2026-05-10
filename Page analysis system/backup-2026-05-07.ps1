# ═══ نسخ احتياطي — Page Analysis System ═══
# التاريخ: 2026-05-07
# التشغيل: كليك يمين → Run with PowerShell
# أو من PowerShell: .\backup-2026-05-07.ps1
# ══════════════════════════════════════════════

$Source      = "C:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system"
$Destination = "C:\Users\my computer\Local Sites\alabeer\app\public\alabeer-hub\Page analysis system_backup_2026-05-07"

Write-Host ""
Write-Host "═══ نسخ احتياطي لنظام تحليل الصفحات ═══" -ForegroundColor Cyan
Write-Host "المصدر:      $Source" -ForegroundColor Yellow
Write-Host "الوجهة: $Destination" -ForegroundColor Yellow
Write-Host ""

if (Test-Path $Destination) {
    Write-Host "المجلد موجود مسبقاً — يتم التحديث..." -ForegroundColor DarkYellow
    Remove-Item $Destination -Recurse -Force
}

Write-Host "جاري النسخ... (قد يستغرق دقيقة)" -ForegroundColor Green
Copy-Item -Path $Source -Destination $Destination -Recurse -Force

$fileCount = (Get-ChildItem $Destination -Recurse -File).Count
$sizeMB = [math]::Round(((Get-ChildItem $Destination -Recurse | Measure-Object -Property Length -Sum).Sum / 1MB), 2)

Write-Host ""
Write-Host "══════════════════════════════════════════" -ForegroundColor Cyan
Write-Host "تم النسخ الاحتياطي بنجاح!" -ForegroundColor Green
Write-Host "عدد الملفات: $fileCount" -ForegroundColor White
Write-Host "الحجم: $sizeMB MB" -ForegroundColor White
Write-Host "المسار: $Destination" -ForegroundColor White
Write-Host "══════════════════════════════════════════" -ForegroundColor Cyan
Write-Host ""
Read-Host "اضغط Enter للإغلاق"
