param(
    [string]$ApiBase = "http://localhost:8080/api/v1"
)

$ErrorActionPreference = "Stop"
$email = "smoke.$([Guid]::NewGuid().ToString('N'))@example.test"
$password = "SmokeTestPass123"

function Invoke-HexbayJson {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Body,
        [string]$Token
    )

    $headers = @{ Accept = "application/json" }
    if ($Token) {
        $headers.Authorization = "Bearer $Token"
    }

    $parameters = @{
        Method = $Method
        Uri = "$ApiBase$Path"
        Headers = $headers
    }
    if ($Body) {
        $parameters.ContentType = "application/json"
        $parameters.Body = $Body | ConvertTo-Json -Depth 6
    }

    Invoke-RestMethod @parameters
}

$health = Invoke-HexbayJson -Method GET -Path "/health"
if (-not $health.success) {
    throw "Health endpoint did not report success."
}

$registration = Invoke-HexbayJson -Method POST -Path "/auth/register/customer" -Body @{
    email = $email
    password = $password
    first_name = "Smoke"
    last_name = "Tester"
    phone = "+94 77 123 4567"
}
if ($registration.data.user.role -ne "customer") {
    throw "Registration returned an unexpected role."
}

$login = Invoke-HexbayJson -Method POST -Path "/auth/login" -Body @{
    email = $email
    password = $password
}
$token = $login.data.access_token
if (-not $token) {
    throw "Login did not return a JWT."
}

$me = Invoke-HexbayJson -Method GET -Path "/auth/me" -Token $token
if ($me.data.user.email -ne $email) {
    throw "Authenticated user response does not match the test account."
}

$forbiddenObserved = $false
try {
    Invoke-HexbayJson -Method GET -Path "/admin/ping" -Token $token | Out-Null
} catch {
    if ($_.Exception.Response.StatusCode.value__ -eq 403) {
        $forbiddenObserved = $true
    } else {
        throw
    }
}
if (-not $forbiddenObserved) {
    throw "Customer unexpectedly accessed the administrator route."
}

Invoke-HexbayJson -Method POST -Path "/auth/logout" -Token $token | Out-Null

$revocationObserved = $false
try {
    Invoke-HexbayJson -Method GET -Path "/auth/me" -Token $token | Out-Null
} catch {
    if ($_.Exception.Response.StatusCode.value__ -eq 401) {
        $revocationObserved = $true
    } else {
        throw
    }
}
if (-not $revocationObserved) {
    throw "Logged-out JWT remained usable."
}

Write-Host "Sprint 1 authentication API smoke test passed for $email"

