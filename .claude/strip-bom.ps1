$extensions = @('*.php', '*.twig', '*.js', '*.ts', '*.tsx', '*.css', '*.scss', '*.json', '*.xml', '*.md', '*.yml', '*.po')
$excluded = '\\(vendor|node_modules|\.git|var)\\'
$utf8NoBom = New-Object System.Text.UTF8Encoding $false

Get-ChildItem -Path '.' -Recurse -File -Include $extensions |
    Where-Object { $_.FullName -notmatch $excluded } |
    ForEach-Object {
        $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
        if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            $text = [System.IO.File]::ReadAllText($_.FullName, [System.Text.Encoding]::UTF8)
            [System.IO.File]::WriteAllText($_.FullName, $text, $utf8NoBom)
        }
    }
