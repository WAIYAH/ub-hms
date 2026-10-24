<#
.SYNOPSIS
    Insert chapter files into the proposal, just before its REFERENCES heading, using Word itself so that
    styles, pictures, tables and landscape pages are carried over exactly. Saves the result as -Out.
#>
param(
    [Parameter(Mandatory = $true)][string]$Base,
    [Parameter(Mandatory = $true)][string]$Parts,
    [Parameter(Mandatory = $true)][string]$Out
)
$ErrorActionPreference = 'Stop'
$word = New-Object -ComObject Word.Application
$word.Visible = $false
$word.DisplayAlerts = 0
try {
    $doc = $word.Documents.Open((Resolve-Path $Base).Path, $false, $false)
    foreach ($part in ($Parts -split ';')) {
        $full = (Resolve-Path $part).Path
        $idx = 0
        for ($i = $doc.Paragraphs.Count; $i -ge 1; $i--) {
            $p = $doc.Paragraphs.Item($i)
            if ($p.OutlineLevel -eq 1 -and $p.Range.Text.Trim() -eq 'REFERENCES') { $idx = $i; break }
        }
        if ($idx -eq 0) { throw 'The REFERENCES heading was not found.' }
        $doc.Paragraphs.Item($idx - 1).Range.InsertParagraphAfter()
        $new = $doc.Paragraphs.Item($idx)
        $new.Range.Style = 'Normal'
        $new.Range.InsertFile($full)
        Write-Output ("inserted " + (Split-Path $full -Leaf) + "; pages now " + $doc.ComputeStatistics(2))
    }
    $doc.SaveAs2([IO.Path]::GetFullPath($Out), 12)
    $doc.Close($false)
} finally {
    $word.Quit()
    [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($word)
}
