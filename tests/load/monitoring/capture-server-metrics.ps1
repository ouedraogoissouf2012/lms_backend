<#
.SYNOPSIS
    Capture CPU/RAM des processus PHP (poste de developpement Windows) pendant un tir de charge k6.

.DESCRIPTION
    Harnais de test de charge k6 — issue #372, Requirement 8 (capture des metriques serveur).
    Echantillonne periodiquement (intervalle configurable, defaut 5s) l'utilisation CPU et RAM de
    tous les processus dont le nom correspond au motif -ProcessNamePattern (defaut "php*", couvre
    php.exe lance en dev via `php artisan serve`, le worker de queue, ou le stub Klassci local
    `php -S` du harnais).

    Ce script est un PROCESSUS INDEPENDANT du tir k6 (Requirement 8.4) : il ne bloque jamais
    `k6 run`, et reciproquement aucun code du harnais k6 n'attend ni ne verifie son etat. Il est
    destine a etre lance dans un terminal separe, avant le tir, et arrete apres (Ctrl+C ou
    -DurationSeconds).

    Choix technique deliberement PAS base sur `Get-Counter` : les jeux de compteurs de performance
    Windows ("Process", "% Processor Time", "Working Set"...) sont localises selon la langue de
    l'OS — sur un Windows installe en francais par exemple, le jeu de compteurs s'appelle
    "Processus" et expose "% temps processeur"/"Plage de travail", pas les noms anglais (verifie en
    conditions reelles sur le poste de developpement : `Get-Counter -Counter "\Process(*)\..."`
    echoue purement et simplement avec "objet specifie non trouve" des que l'OS n'est pas
    anglophone). Coder les noms anglais en dur aurait rendu ce script totalement inoperant sur une
    partie non negligeable des postes de developpement. `Get-Process` interroge directement l'objet
    processus du systeme d'exploitation (API Win32 sous-jacente) et ne depend d'aucune table de
    noms localisee : c'est donc l'implementation robuste retenue ici, au prix d'un calcul manuel du
    %CPU par delta de temps processeur cumule entre deux echantillons (technique standard,
    equivalente a celle de `top`/`pidstat` cote Unix).

    Schema de sortie CSV strictement identique a capture-server-metrics.sh (Linux/VPS), pour une
    analyse en aval independante de l'OS ayant produit la capture :
        timestamp_iso8601,cpu_percent,ram_used_mb,ram_total_mb

.PARAMETER IntervalSeconds
    Intervalle d'echantillonnage en secondes. Defaut : 5 (design docs §6, Requirement 8.1).

.PARAMETER ProcessNamePattern
    Motif de nom de processus (wildcard `Get-Process -Name`). Defaut : "php*".

.PARAMETER DurationSeconds
    Duree totale de capture en secondes. Si omis, la capture continue jusqu'a interruption manuelle
    (Ctrl+C) — usage recommande : lancer ce script dans un terminal dedie avant `k6 run`, l'arreter
    (Ctrl+C) une fois le tir termine (Requirement 8.1, 8.2).

.PARAMETER RunLabel
    Etiquette optionnelle ajoutee au nom du fichier de sortie (ex. "baseline-pre-374"), pour tracer
    explicitement a quelle etape du plan de scalabilite (#367/#374/#378/#379/#380) une capture
    correspond — meme convention que LOAD_TEST_RUN_LABEL utilise par les scenarios k6
    (Requirement 11.2).

.PARAMETER OutputPath
    Chemin explicite du fichier CSV de sortie. Si omis, genere sous tests/load/results/ selon la
    convention de nommage horodatee du harnais : server-metrics__<YYYYMMDDTHHmmssZ>[__<label>].csv.

.EXAMPLE
    # Terminal dedie, capture toutes les 5s jusqu'a Ctrl+C
    .\tests\load\monitoring\capture-server-metrics.ps1

.EXAMPLE
    # Capture bornee a 10 minutes, etiquetee pour une etape du plan de scalabilite
    .\tests\load\monitoring\capture-server-metrics.ps1 -DurationSeconds 600 -RunLabel "baseline-pre-374"

.NOTES
    Requirements couverts : 8.1 (demarrage de la capture en parallele du tir), 8.2 (arret + fichier
    de sortie exploitable), 8.4 (echec non bloquant pour le tir k6).
    Le tout premier echantillon d'une capture affiche systematiquement cpu_percent=0 : le calcul du
    %CPU repose sur un delta de temps processeur cumule entre deux echantillons successifs, donc
    indisponible tant qu'un seul echantillon a ete pris (comportement attendu, sans impact sur les
    echantillons suivants).
#>

[CmdletBinding()]
param(
    [ValidateRange(1, 3600)]
    [int]$IntervalSeconds = 5,

    [ValidateNotNullOrEmpty()]
    [string]$ProcessNamePattern = 'php*',

    [Nullable[int]]$DurationSeconds = $null,

    [string]$RunLabel = '',

    [string]$OutputPath = ''
)

$ErrorActionPreference = 'Stop'

if ($null -ne $DurationSeconds -and $DurationSeconds -lt 1) {
    throw "DurationSeconds doit etre un entier positif (recu: $DurationSeconds)."
}

# --- Resolution du repertoire de sortie : tests/load/results/ (cf. arborescence du harnais, tache 1) ---
$resultsDir = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\results'))
if (-not (Test-Path $resultsDir)) {
    New-Item -ItemType Directory -Path $resultsDir -Force | Out-Null
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    # Convention de nommage du harnais (design docs, Data Models) :
    # server-metrics__<YYYYMMDDTHHmmssZ>[__<run-label>].csv
    $timestamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
    $fileName = "server-metrics__$timestamp"
    if (-not [string]::IsNullOrWhiteSpace($RunLabel)) {
        $safeLabel = $RunLabel -replace '[^A-Za-z0-9_\-]', '-'
        $fileName += "__$safeLabel"
    }
    $fileName += '.csv'
    $OutputPath = Join-Path $resultsDir $fileName
}
else {
    $OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
}

Write-Host "Capture CPU/RAM (processus '$ProcessNamePattern') -> $OutputPath"
$stopHint = if ($DurationSeconds) { "ou apres ${DurationSeconds}s" } else { '' }
Write-Host "Intervalle : ${IntervalSeconds}s. Arret : Ctrl+C $stopHint"

# --- RAM totale de la machine (constante pour toute la duree de la capture) ---
$totalMemoryMb = [math]::Round(
    (Get-CimInstance -ClassName Win32_OperatingSystem).TotalVisibleMemorySize / 1KB, 2
)
$logicalProcessors = [Environment]::ProcessorCount

$writer = [System.IO.StreamWriter]::new($OutputPath, $false, [System.Text.Encoding]::UTF8)
$startedAt = Get-Date
$sampleCount = 0
$missingProcessWarned = $false

# Etat du dernier echantillon, par PID : temps processeur cumule (secondes) + instant de mesure —
# necessaire pour calculer un %CPU par delta (cf. .NOTES et section DESCRIPTION ci-dessus).
$previousCpuByPid = @{}

try {
    $writer.WriteLine('timestamp_iso8601,cpu_percent,ram_used_mb,ram_total_mb')
    $writer.Flush()

    while ($true) {
        $sampledAt = Get-Date
        $timestampIso = $sampledAt.ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')

        $cpuPercent = 0.0
        $ramUsedMb = 0.0
        $currentCpuByPid = @{}

        # Requirement 8.4 : un echec ponctuel (aucun processus php* actif a cet instant, acces
        # refuse a un processus systeme, etc.) ne doit jamais faire echouer la capture ni, a
        # fortiori, le tir k6 — on consigne 0 et on continue d'echantillonner.
        try {
            $procs = Get-Process -Name $ProcessNamePattern -ErrorAction Stop

            $ramUsedMb = [math]::Round((($procs | Measure-Object -Property WorkingSet64 -Sum).Sum) / 1MB, 2)

            $cpuDeltaSeconds = 0.0
            $maxElapsedSeconds = 0.0
            foreach ($proc in $procs) {
                # $proc.CPU = temps processeur cumule (secondes) depuis le demarrage du processus,
                # $null si inaccessible (droits insuffisants) — traite comme 0 dans ce cas.
                $cpuTotalSeconds = if ($null -ne $proc.CPU) { $proc.CPU } else { 0.0 }
                $currentCpuByPid[$proc.Id] = @{ CpuSeconds = $cpuTotalSeconds; SampledAt = $sampledAt }

                $previous = $previousCpuByPid[$proc.Id]
                if ($previous) {
                    $delta = $cpuTotalSeconds - $previous.CpuSeconds
                    if ($delta -gt 0) { $cpuDeltaSeconds += $delta }

                    $elapsed = ($sampledAt - $previous.SampledAt).TotalSeconds
                    if ($elapsed -gt $maxElapsedSeconds) { $maxElapsedSeconds = $elapsed }
                }
                # Processus vu pour la premiere fois (pas d'echantillon precedent) : pas de delta
                # calculable pour ce cycle — sa contribution CPU sera prise en compte a partir du
                # prochain echantillon (cf. .NOTES).
            }

            if ($maxElapsedSeconds -gt 0) {
                # Delta de temps processeur cumule / (duree ecoulee x nombre de coeurs logiques),
                # ramene a une echelle 0-100% coherente avec la lecture usuelle d'un %CPU global
                # (equivalent Windows du %CPU cumule affiche par `top`/`pidstat` cote Linux).
                $cpuPercent = [math]::Round(($cpuDeltaSeconds / ($maxElapsedSeconds * $logicalProcessors)) * 100, 2)
            }

            $missingProcessWarned = $false
        }
        catch {
            # Get-Process leve une exception terminante si AUCUNE instance ne correspond au motif
            # (ex. le stub Klassci / `php artisan serve` pas encore demarre) — attendu en debut/fin
            # de tir, pas une erreur de capture. On avertit une seule fois pour eviter le bruit.
            if (-not $missingProcessWarned) {
                Write-Warning "Aucun processus correspondant a '$ProcessNamePattern' (CPU/RAM consignes a 0 tant qu'aucune instance n'est trouvee). Detail : $($_.Exception.Message)"
                $missingProcessWarned = $true
            }
        }

        $previousCpuByPid = $currentCpuByPid

        $writer.WriteLine("$timestampIso,$cpuPercent,$ramUsedMb,$totalMemoryMb")
        $writer.Flush()
        $sampleCount++

        if ($DurationSeconds -and ((Get-Date) - $startedAt).TotalSeconds -ge $DurationSeconds) {
            break
        }

        Start-Sleep -Seconds $IntervalSeconds
    }
}
finally {
    # Requirement 8.2 : garantir un fichier de sortie exploitable a l'arret, y compris sur Ctrl+C
    # (SIGINT) — le bloc finally s'execute lors du deroulement de la PipelineStoppedException levee
    # par PowerShell a l'interruption manuelle, symetrique au `trap SIGINT` du script Linux.
    $writer.Flush()
    $writer.Dispose()
    Write-Host "Capture arretee. $sampleCount echantillon(s) ecrit(s) dans $OutputPath"
}
