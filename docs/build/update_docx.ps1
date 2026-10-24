<#
.SYNOPSIS
    Open a .docx in Word, refresh every field (contents, lists of figures and tables, page numbers),
    save it, and optionally export a PDF so the layout can be checked.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File update_docx.ps1 -Path ..\chapters\X.docx -Pdf C:\temp\X.pdf
#>
param(
    [Parameter(Mandatory = $true)][string]$Path,
    [string]$Pdf = ''
)
$ErrorActionPreference = 'Stop'
$full = (Resolve-Path $Path).Path
Set-ItemProperty -Path $full -Name IsReadOnly -Value $false
$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
try {
    $doc = $word.Documents.Open($full, $false, $false)
    for ($pass = 1; $pass -le 3; $pass++) {
        $doc.Repaginate()
        foreach ($toc in $doc.TablesOfContents) { $toc.Update() }
        [void]$doc.Fields.Update()
    }
    # the updateFields flag set by the builder is no longer needed once fields are fresh
    $doc.Save()
    if ($Pdf) {
        $doc.ExportAsFixedFormat($Pdf, 17)
    }
    $pages = $doc.ComputeStatistics(2)
    $words = $doc.ComputeStatistics(0)
    Write-Output "pages=$pages words=$words"
    $doc.Close($false)
} finally {
    $word.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)
}
