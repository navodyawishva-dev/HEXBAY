param(
    [string]$ApiBase = "http://localhost:8080/api/v1",
    [string]$PhpExe = "C:\wamp64\bin\php\php8.3.6\php.exe"
)

$ErrorActionPreference = "Stop"
$suffix = [Guid]::NewGuid().ToString("N")
$sellerEmail = "seller.$suffix@example.test"
$seller2Email = "seller2.$suffix@example.test"
$buyerEmail = "buyer.$suffix@example.test"
$adminEmail = "admin.$suffix@example.test"
$sellerPassword = "SellerA1!$suffix"
$seller2Password = "SellerB1!$suffix"
$buyerPassword = "BuyerA1!$suffix"
$adminPassword = "AdminA1!$suffix"
$sellerBrandName = "Sprint Three Seller $suffix"

$env:HEX_TEST_ADMIN_EMAIL = $adminEmail
$env:HEX_TEST_ADMIN_PASSWORD = $adminPassword
$env:HEX_TEST_SELLER_EMAIL = $sellerEmail
$env:HEX_TEST_SELLER2_EMAIL = $seller2Email
$env:HEX_TEST_BUYER_EMAIL = $buyerEmail
$env:HEX_TEST_SUFFIX = $suffix
$env:HEX_TEST_CATEGORY_SLUG = "smoke-category-$suffix"
$env:HEX_TEST_SELLER_BRAND = $sellerBrandName
$imageFixture = (Resolve-Path `
    "frontend\src\assets\brand\hexbay-logo-dark-v2.png").Path
$invalidUploadFixture = (Resolve-Path "frontend\package.json").Path

function Invoke-HexbayJson {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Body,
        [string]$Token
    )
    $headers = @{ Accept = "application/json" }
    if ($Token) { $headers.Authorization = "Bearer $Token" }
    $arguments = @{
        Method = $Method
        Uri = "$ApiBase$Path"
        Headers = $headers
    }
    if ($Body) {
        $arguments.ContentType = "application/json"
        $arguments.Body = $Body | ConvertTo-Json -Depth 8
    }
    try {
        Invoke-RestMethod @arguments
    } catch {
        Write-Host "API request failed: $Method $Path"
        if ($_.ErrorDetails.Message) {
            Write-Host $_.ErrorDetails.Message
        }
        throw
    }
}

function Invoke-HexbayUpload {
    param(
        [string]$Path,
        [string]$Token,
        [string]$FilePath,
        [string]$UploadName,
        [hashtable]$Fields = @{},
        [int]$ExpectedStatus = 201
    )
    $curlArguments = @(
        "--silent",
        "--show-error",
        "--request", "POST",
        "--url", "$ApiBase$Path",
        "--header", "Accept: application/json",
        "--header", "Authorization: Bearer $Token",
        "--form", "file=@$FilePath;filename=$UploadName"
    )
    foreach ($key in $Fields.Keys) {
        $curlArguments += @("--form", "$key=$($Fields[$key])")
    }
    $curlArguments += @("--write-out", "`nHTTP_STATUS:%{http_code}")
    $raw = (& curl.exe @curlArguments) -join "`n"
    if ($LASTEXITCODE -ne 0) { throw "Upload request failed to run." }
    if ($raw -notmatch "(?s)^(.*)\r?\nHTTP_STATUS:(\d+)$") {
        throw "Upload response status could not be read."
    }
    $body = $Matches[1]
    $statusCode = [int]$Matches[2]
    $payload = $body | ConvertFrom-Json
    if ($statusCode -ne $ExpectedStatus) {
        throw "Expected upload status $ExpectedStatus, received $statusCode. $($payload.message)"
    }
    [PSCustomObject]@{ Status = $statusCode; Payload = $payload }
}

try {
    & $PhpExe -d xdebug.mode=off "backend\tests\create_admin_fixture.php" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Administrator fixture creation failed." }

    $commission = Invoke-HexbayJson -Method GET -Path "/commission/current"
    if ([decimal]$commission.data.commission.percentage -ne 5.00) {
        throw "Expected the approved initial 5% commission rule."
    }

    $seller = Invoke-HexbayJson -Method POST -Path "/auth/register/vendor" -Body @{
        email = $sellerEmail
        password = $sellerPassword
        first_name = "Seller"
        last_name = "Tester"
        phone = "+94 77 123 4567"
        business_name = "Sprint Two Technology"
    }
    $sellerToken = $seller.data.access_token

    $application = Invoke-HexbayJson -Method POST -Path "/seller/shop-application" `
        -Token $sellerToken -Body @{
            shop_name = "Sprint Two Technology"
            description = "A temporary technology shop used for integration testing."
            address = "10 Test Street, Colombo"
            contact_phone = "+94 77 123 4567"
            contact_email = $sellerEmail
            legal_name = "Sprint Two Technology Private Limited"
            business_registration_reference = "TEST-$suffix"
            commission_rule_id = [int]$commission.data.commission.id
            commission_accepted = $true
        }
    if ($application.data.application.verification_status -ne "pending") {
        throw "Seller application was not pending."
    }

    Invoke-HexbayUpload -Path "/seller/verification-documents" `
        -Token $sellerToken -FilePath $invalidUploadFixture `
        -UploadName "malicious.php" `
        -Fields @{ document_type = "business_registration" } `
        -ExpectedStatus 422 | Out-Null

    $verificationUpload = Invoke-HexbayUpload `
        -Path "/seller/verification-documents" `
        -Token $sellerToken -FilePath $imageFixture `
        -UploadName "business-registration.png" `
        -Fields @{ document_type = "business_registration" }
    if (
        $verificationUpload.Payload.data.document.original_filename `
            -ne "business-registration.png"
    ) {
        throw "Protected verification upload metadata was incorrect."
    }

    $buyer = Invoke-HexbayJson -Method POST -Path "/auth/register/customer" -Body @{
        email = $buyerEmail
        password = $buyerPassword
        first_name = "Buyer"
        last_name = "Tester"
    }

    $adminLogin = Invoke-HexbayJson -Method POST -Path "/auth/login" -Body @{
        email = $adminEmail
        password = $adminPassword
    }
    $adminToken = $adminLogin.data.access_token
    $buyerToken = $buyer.data.access_token

    $category = Invoke-HexbayJson -Method POST -Path "/admin/categories" `
        -Token $adminToken -Body @{
            name = "Smoke Category $suffix"
            slug = $env:HEX_TEST_CATEGORY_SLUG
            description = "Temporary category used for the Sprint 2 API test."
            sort_order = 9999
            is_active = $true
            requires_listing_approval = $true
        }
    $categoryId = [int]$category.data.category.id
    $specification = Invoke-HexbayJson -Method POST `
        -Path "/admin/categories/$categoryId/specifications" `
        -Token $adminToken -Body @{
            code = "test_generation"
            display_name = "Test generation"
            data_type = "option"
            unit = ""
            is_required = $true
            is_filterable = $true
            is_compatibility_field = $true
            minimum_value = ""
            maximum_value = ""
            sort_order = 1
            is_active = $true
            options = @(
                @{ display_value = "Generation A" },
                @{ display_value = "Generation B" }
            )
        }
    if ($specification.data.specification.options.Count -ne 2) {
        throw "Administrator specification options were not saved."
    }
    Invoke-HexbayJson -Method POST `
        -Path "/admin/categories/$categoryId/specifications" `
        -Token $adminToken -Body @{
            code = "test_boolean"
            display_name = "Boolean storage regression"
            data_type = "boolean"
            is_required = $false
            is_filterable = $false
            is_compatibility_field = $false
            is_active = $true
            sort_order = 2
        } | Out-Null

    $dashboard = Invoke-HexbayJson -Method GET -Path "/admin/dashboard" -Token $adminToken
    if ([int]$dashboard.data.counts.pending_shops -lt 1) {
        throw "Administrator dashboard did not show the pending application."
    }

    $applications = Invoke-HexbayJson -Method GET `
        -Path "/admin/shop-applications?status=pending" -Token $adminToken
    $target = $applications.data.applications |
        Where-Object { $_.owner_email -eq $sellerEmail } |
        Select-Object -First 1
    if (
        -not $target `
        -or [decimal]$target.percentage_snapshot -ne 5.00 `
        -or [int]$target.document_count -ne 1
    ) {
        throw "Administrator could not verify the seller's 5% acceptance."
    }

    $adminDocuments = Invoke-HexbayJson -Method GET `
        -Path "/admin/shop-applications/$($target.id)/documents" `
        -Token $adminToken
    $protectedDocument = $adminDocuments.data.documents | Select-Object -First 1
    if (-not $protectedDocument) {
        throw "Administrator could not see the protected document metadata."
    }
    $adminDownload = Invoke-WebRequest -UseBasicParsing `
        -Uri "$ApiBase/admin/verification-documents/$($protectedDocument.id)/download" `
        -Headers @{ Authorization = "Bearer $adminToken" }
    if ($adminDownload.StatusCode -ne 200) {
        throw "Administrator could not download the protected verification document."
    }
    $buyerDocumentWasBlocked = $false
    try {
        Invoke-WebRequest -UseBasicParsing `
            -Uri "$ApiBase/admin/verification-documents/$($protectedDocument.id)/download" `
            -Headers @{ Authorization = "Bearer $buyerToken" } | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 403) {
            $buyerDocumentWasBlocked = $true
        } else {
            throw
        }
    }
    if (-not $buyerDocumentWasBlocked) {
        throw "A buyer was incorrectly allowed to download a protected document."
    }

    Invoke-HexbayJson -Method POST `
        -Path "/admin/shop-applications/$($target.id)/decision" `
        -Token $adminToken -Body @{
            decision = "approved"
            reason = ""
            notes = "Integration test approval."
        } | Out-Null

    $sellerStatus = Invoke-HexbayJson -Method GET `
        -Path "/seller/shop-application" -Token $sellerToken
    if ($sellerStatus.data.application.shop_status -ne "approved") {
        throw "Approved shop status was not visible to the seller."
    }

    $notifications = Invoke-HexbayJson -Method GET -Path "/notifications" -Token $sellerToken
    $approvalNotice = $notifications.data.notifications |
        Where-Object { $_.type -eq "shop_approved" } |
        Select-Object -First 1
    if (-not $approvalNotice -or $approvalNotice.message -notmatch "5\.00%") {
        throw "Seller approval notification did not disclose the accepted commission."
    }

    $logoUpload = Invoke-HexbayUpload -Path "/seller/profile/logo" `
        -Token $sellerToken -FilePath $imageFixture `
        -UploadName "shop-logo.png" -ExpectedStatus 200
    $logoFilename = $logoUpload.Payload.data.shop.logo_path
    if ($logoFilename -notmatch "^[a-f0-9]{32}\.png$") {
        throw "Shop logo was not stored with a safe random filename."
    }
    $logoToken = $logoFilename.Split(".")[0]
    $publicLogo = Invoke-WebRequest -UseBasicParsing `
        -Uri "$ApiBase/media/shop-logos/$logoToken"
    if (
        $publicLogo.StatusCode -ne 200 `
        -or $publicLogo.Headers["Content-Type"] -notmatch "image/png"
    ) {
        throw "The stored shop logo was not safely streamable."
    }

    $seller2 = Invoke-HexbayJson -Method POST -Path "/auth/register/vendor" -Body @{
        email = $seller2Email
        password = $seller2Password
        first_name = "Second"
        last_name = "Seller"
        phone = "+94 77 555 0101"
        business_name = "Isolated Technology"
    }
    $seller2Token = $seller2.data.access_token
    Invoke-HexbayJson -Method POST -Path "/seller/shop-application" `
        -Token $seller2Token -Body @{
            shop_name = "Isolated Technology"
            description = "Second temporary shop for ownership-isolation testing."
            address = "20 Isolation Street, Colombo"
            contact_phone = "+94 77 555 0101"
            contact_email = $seller2Email
            legal_name = "Isolated Technology Private Limited"
            business_registration_reference = "ISO-$suffix"
            commission_rule_id = [int]$commission.data.commission.id
            commission_accepted = $true
        } | Out-Null
    Invoke-HexbayUpload -Path "/seller/verification-documents" `
        -Token $seller2Token -FilePath $imageFixture `
        -UploadName "seller-two-registration.png" `
        -Fields @{ document_type = "business_registration" } | Out-Null
    $secondApplications = Invoke-HexbayJson -Method GET `
        -Path "/admin/shop-applications?status=pending" -Token $adminToken
    $secondTarget = $secondApplications.data.applications |
        Where-Object { $_.owner_email -eq $seller2Email } |
        Select-Object -First 1
    if (-not $secondTarget) {
        throw "Second seller application was not visible to the administrator."
    }
    Invoke-HexbayJson -Method POST `
        -Path "/admin/shop-applications/$($secondTarget.id)/decision" `
        -Token $adminToken -Body @{
            decision = "approved"
            reason = ""
            notes = "Isolation-test approval."
        } | Out-Null

    $sellerDashboard = Invoke-HexbayJson -Method GET `
        -Path "/seller/dashboard" -Token $sellerToken
    if ([int]$sellerDashboard.data.counts.products -ne 0) {
        throw "A newly approved seller should begin with an empty catalogue."
    }

    $catalogue = Invoke-HexbayJson -Method GET `
        -Path "/seller/catalogue-options" -Token $sellerToken
    if (-not $catalogue.data.categories) {
        throw "Seller catalogue options were not loaded."
    }
    $testOption = $specification.data.specification.options | Select-Object -First 1
    $testOptionCode = [string]$testOption.value_code
    if ($testOptionCode -ne "generation_a") {
        throw "The controlled specification option was not returned correctly."
    }

    $invalidSpecificationWasBlocked = $false
    try {
        Invoke-HexbayJson -Method POST `
            -Path "/seller/listings" -Token $sellerToken -Body @{
                category_id = $categoryId
                brand_name = $sellerBrandName
                product_name = "Invalid Specification Product"
                model = "INVALID-$suffix"
                sku = "INVALID-$($suffix.Substring(0, 10))"
                condition_type = "new"
                price = "125000.00"
                vendor_description = "This listing must fail controlled-value validation."
                warranty_summary = "Test warranty"
                initial_stock = 1
                specifications = @{
                    test_generation = "not_an_allowed_option"
                }
            } | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 422) {
            $invalidSpecificationWasBlocked = $true
        } else {
            throw
        }
    }
    if (-not $invalidSpecificationWasBlocked) {
        throw "An invalid controlled seller specification was accepted."
    }

    $sellerListing = Invoke-HexbayJson -Method POST `
        -Path "/seller/listings" -Token $sellerToken -Body @{
            category_id = $categoryId
            brand_name = $sellerBrandName
            product_name = "Seller Workspace Test Product"
            model = "S3-$suffix"
            sku = "S3-$($suffix.Substring(0, 12))"
            condition_type = "new"
            price = "125000.00"
            vendor_description = "Temporary product created through the seller workspace."
            warranty_summary = "One year test warranty"
            initial_stock = 3
            specifications = @{
                test_generation = $testOptionCode
                test_boolean = $false
            }
        }
    $sellerListingId = [int]$sellerListing.data.listing.id
    if ($sellerListing.data.listing.specifications.test_boolean -cne $false) {
        throw "A false boolean product specification was not preserved."
    }
    if ($sellerListing.data.listing.status -ne "pending_approval") {
        throw "Seller listing did not enter the required approval queue."
    }

    Invoke-HexbayUpload -Path "/seller/listings/$sellerListingId/images" `
        -Token $buyerToken -FilePath $imageFixture `
        -UploadName "buyer-forbidden.png" -ExpectedStatus 403 | Out-Null

    Invoke-HexbayUpload -Path "/seller/listings/$sellerListingId/images" `
        -Token $sellerToken -FilePath $invalidUploadFixture `
        -UploadName "product-image.php" -ExpectedStatus 422 | Out-Null

    $productImageUpload = Invoke-HexbayUpload `
        -Path "/seller/listings/$sellerListingId/images" `
        -Token $sellerToken -FilePath $imageFixture `
        -UploadName "seller-product.png" `
        -Fields @{ alt_text = "Sprint 3 test product on a dark background" }
    $productImage = $productImageUpload.Payload.data.image
    if ($productImage.stored_filename -notmatch "^[a-f0-9]{32}\.png$") {
        throw "Product image was not stored with a safe random filename."
    }
    $productImageToken = $productImage.stored_filename.Split(".")[0]
    $publicProductImage = Invoke-WebRequest -UseBasicParsing `
        -Uri "$ApiBase/media/product-images/$productImageToken"
    if ($publicProductImage.StatusCode -ne 200) {
        throw "The product image was not safely streamable."
    }

    $secondProductImage = Invoke-HexbayUpload `
        -Path "/seller/listings/$sellerListingId/images" `
        -Token $sellerToken -FilePath $imageFixture `
        -UploadName "seller-product-second.png"
    Invoke-HexbayJson -Method POST `
        -Path "/seller/listings/$sellerListingId/images/$($secondProductImage.Payload.data.image.id)/delete" `
        -Token $sellerToken | Out-Null
    $listingWithImages = Invoke-HexbayJson -Method GET `
        -Path "/seller/listings/$sellerListingId" -Token $sellerToken
    if ($listingWithImages.data.listing.images.Count -ne 1) {
        throw "Product image upload/delete state was not preserved."
    }

    $secondSellerWasIsolated = $false
    try {
        Invoke-HexbayJson -Method GET `
            -Path "/seller/listings/$sellerListingId" `
            -Token $seller2Token | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 404) {
            $secondSellerWasIsolated = $true
        } else {
            throw
        }
    }
    if (-not $secondSellerWasIsolated) {
        throw "A second seller could read another shop's product listing."
    }

    $stock = Invoke-HexbayJson -Method POST `
        -Path "/seller/inventory/$sellerListingId/adjust" `
        -Token $sellerToken -Body @{
            quantity_delta = 2
            reason = "Integration test restock"
        }
    if ([int]$stock.data.inventory.quantity_on_hand -ne 5) {
        throw "Seller stock adjustment did not preserve the correct quantity."
    }

    $sellerFinance = Invoke-HexbayJson -Method GET `
        -Path "/seller/finance" -Token $sellerToken
    if ([decimal]$sellerFinance.data.summary.available_balance -ne 0) {
        throw "A seller without completed orders must not have payout funds."
    }

    $buyerWasBlocked = $false
    try {
        Invoke-HexbayJson -Method GET -Path "/seller/listings" `
            -Token $buyerToken | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 403) {
            $buyerWasBlocked = $true
        } else {
            throw
        }
    }
    if (-not $buyerWasBlocked) {
        throw "A buyer was incorrectly allowed into the seller catalogue."
    }

    $sellerPending = Invoke-HexbayJson -Method GET `
        -Path "/admin/listings?status=pending_approval" -Token $adminToken
    if (-not ($sellerPending.data.listings |
        Where-Object { [int]$_.id -eq $sellerListingId })) {
        throw "The seller-created listing was not visible in administrator moderation."
    }
    Invoke-HexbayJson -Method POST `
        -Path "/admin/listings/$sellerListingId/decision" `
        -Token $adminToken -Body @{ status = "active"; reason = "" } | Out-Null

    $publicSearch = [Uri]::EscapeDataString("Seller Workspace Test Product")
    $publicCatalogue = Invoke-HexbayJson -Method GET `
        -Path "/products?search=$publicSearch&available=true&sort=price_low"
    $publicProduct = $publicCatalogue.data.products |
        Where-Object { [int]$_.id -eq [int]$sellerListing.data.listing.canonical_product_id } |
        Select-Object -First 1
    if (-not $publicProduct) {
        throw "The active seller listing was not visible in the public catalogue."
    }
    if ([int]$publicProduct.offer_count -lt 1 -or [int]$publicProduct.available_quantity -lt 1) {
        throw "The public catalogue returned incorrect totals: offers=$($publicProduct.offer_count), available=$($publicProduct.available_quantity)."
    }
    $publicDetail = Invoke-HexbayJson -Method GET `
        -Path "/products/$($publicProduct.id)"
    $publicOffer = $publicDetail.data.product.offers |
        Where-Object { [int]$_.listing_id -eq $sellerListingId } |
        Select-Object -First 1
    if (-not $publicOffer) {
        throw "The public product page did not include the approved seller offer."
    }
    $publicShop = Invoke-HexbayJson -Method GET `
        -Path "/shops/$($publicOffer.shop_id)"
    if (-not ($publicShop.data.shop.products |
        Where-Object { [int]$_.listing_id -eq $sellerListingId })) {
        throw "The public shop page did not include its active listing."
    }
    $publicFeatured = Invoke-HexbayJson -Method GET -Path "/featured-products"
    if ($null -eq $publicFeatured.data.products) {
        throw "The featured-products endpoint did not return a product collection."
    }

    $seller2Listing = Invoke-HexbayJson -Method POST `
        -Path "/seller/listings" -Token $seller2Token -Body @{
            category_id = $categoryId
            brand_name = $sellerBrandName
            product_name = "Seller Workspace Test Product"
            model = "S3-$suffix"
            sku = "S4B-$($suffix.Substring(0, 12))"
            condition_type = "new"
            price = "123000.00"
            vendor_description = "A second approved offer for multi-vendor checkout."
            warranty_summary = "One year second-shop warranty"
            initial_stock = 4
            specifications = @{
                test_generation = $testOptionCode
            }
        }
    $seller2ListingId = [int]$seller2Listing.data.listing.id
    Invoke-HexbayJson -Method POST `
        -Path "/admin/listings/$seller2ListingId/decision" `
        -Token $adminToken -Body @{ status = "active"; reason = "" } | Out-Null
    $multiVendorProduct = Invoke-HexbayJson -Method GET `
        -Path "/products/$($publicProduct.id)"
    if ($multiVendorProduct.data.product.offers.Count -ne 2) {
        throw "The public comparison did not group both approved seller offers."
    }

    $secondSellerStockWasIsolated = $false
    try {
        Invoke-HexbayJson -Method POST `
            -Path "/seller/inventory/$sellerListingId/adjust" `
            -Token $seller2Token -Body @{
                quantity_delta = 1
                reason = "Unauthorized isolation test"
            } | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 404) {
            $secondSellerStockWasIsolated = $true
        } else {
            throw
        }
    }
    if (-not $secondSellerStockWasIsolated) {
        throw "A second seller could change another shop's inventory."
    }

    $env:HEX_TEST_LISTING_ID = [string]$sellerListingId
    $businessFixtureJson = & $PhpExe -d xdebug.mode=off `
        "backend\tests\create_sprint3_business_fixture.php"
    if ($LASTEXITCODE -ne 0) { throw "Sprint 3 business fixture creation failed." }
    $businessFixture = $businessFixtureJson | ConvertFrom-Json

    $sellerOrders = Invoke-HexbayJson -Method GET `
        -Path "/seller/orders" -Token $sellerToken
    if ($sellerOrders.data.orders.Count -ne 2) {
        throw "Seller order shell did not return both owned sub-orders."
    }
    $secondSellerOrderWasIsolated = $false
    try {
        Invoke-HexbayJson -Method POST `
            -Path "/seller/orders/$($businessFixture.pending_sub_order_id)/status" `
            -Token $seller2Token -Body @{ status = "processing"; reason = "" } |
            Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 404) {
            $secondSellerOrderWasIsolated = $true
        } else {
            throw
        }
    }
    if (-not $secondSellerOrderWasIsolated) {
        throw "A second seller could update another shop's order."
    }
    $processingOrder = Invoke-HexbayJson -Method POST `
        -Path "/seller/orders/$($businessFixture.pending_sub_order_id)/status" `
        -Token $sellerToken -Body @{ status = "processing"; reason = "" }
    if ($processingOrder.data.order.status -ne "processing") {
        throw "Seller could not move a pending order to processing."
    }
    foreach ($checkpoint in @("stock_verified", "items_packed", "delivery_address_verified")) {
        Invoke-HexbayJson -Method POST `
            -Path "/seller/orders/$($businessFixture.pending_sub_order_id)/fulfilment-checkpoints" `
            -Token $sellerToken -Body @{ checkpoint_code = $checkpoint } | Out-Null
    }
    $shippedOrder = Invoke-HexbayJson -Method POST `
        -Path "/seller/orders/$($businessFixture.pending_sub_order_id)/status" `
        -Token $sellerToken -Body @{
            status = "shipped"
            reason = ""
            delivery_method = "third_party_courier"
            delivery_partner = "Smoke Test Courier"
            tracking_reference = "TRACK-$($suffix.Substring(0, 12))"
            estimated_delivery_date = (Get-Date).AddDays(5).ToString("yyyy-MM-dd")
            shipment_note = "Automated marketplace smoke-test shipment."
        }
    if ($shippedOrder.data.order.status -ne "shipped") {
        throw "Seller could not mark an owned processing order as shipped."
    }

    $sellerReviews = Invoke-HexbayJson -Method GET `
        -Path "/seller/reviews" -Token $sellerToken
    $verifiedReview = $sellerReviews.data.reviews |
        Where-Object { [int]$_.id -eq [int]$businessFixture.review_id } |
        Select-Object -First 1
    if (-not $verifiedReview -or -not [bool]$verifiedReview.is_verified_purchase) {
        throw "Seller review view did not include the verified-purchase review."
    }

    $completedFinance = Invoke-HexbayJson -Method GET `
        -Path "/seller/finance" -Token $sellerToken
    if (
        [decimal]$completedFinance.data.summary.gross_sales -ne 100000.00 `
        -or [decimal]$completedFinance.data.summary.commission -ne 5000.00 `
        -or [decimal]$completedFinance.data.summary.net_sales -ne 95000.00
    ) {
        throw "Seller finance did not preserve gross, 5% commission and net totals."
    }
    $payout = Invoke-HexbayJson -Method POST `
        -Path "/seller/payouts" -Token $sellerToken -Body @{ amount = "50000.00" }
    if (
        $payout.data.payout.status -ne "pending" `
        -or [decimal]$payout.data.payout.amount -ne 50000.00
    ) {
        throw "Valid simulated seller payout was not recorded."
    }
    $excessPayoutWasBlocked = $false
    try {
        Invoke-HexbayJson -Method POST `
            -Path "/seller/payouts" -Token $sellerToken `
            -Body @{ amount = "50000.00" } | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 409) {
            $excessPayoutWasBlocked = $true
        } else {
            throw
        }
    }
    if (-not $excessPayoutWasBlocked) {
        throw "A payout exceeding the remaining balance was not blocked."
    }
    $isolatedFinance = Invoke-HexbayJson -Method GET `
        -Path "/seller/finance" -Token $seller2Token
    if ([decimal]$isolatedFinance.data.summary.net_sales -ne 0) {
        throw "A second seller could see another shop's finance totals."
    }

    $fixtureJson = & $PhpExe -d xdebug.mode=off `
        "backend\tests\create_moderation_fixture.php"
    if ($LASTEXITCODE -ne 0) { throw "Moderation fixture creation failed." }
    $fixture = $fixtureJson | ConvertFrom-Json

    $moderationDashboard = Invoke-HexbayJson -Method GET `
        -Path "/admin/dashboard" -Token $adminToken
    if (
        [int]$moderationDashboard.data.counts.pending_listings -lt 1 -or
        [int]$moderationDashboard.data.counts.open_complaints -lt 1 -or
        [int]$moderationDashboard.data.counts.open_reports -lt 1
    ) {
        throw "Administrator dashboard did not include the moderation queues."
    }

    $listings = Invoke-HexbayJson -Method GET `
        -Path "/admin/listings?status=pending_approval" -Token $adminToken
    $targetListing = $listings.data.listings |
        Where-Object { [int]$_.id -eq [int]$fixture.listing_id } |
        Select-Object -First 1
    if (-not $targetListing) { throw "Pending listing was not visible to the administrator." }
    Invoke-HexbayJson -Method POST `
        -Path "/admin/listings/$($fixture.listing_id)/decision" `
        -Token $adminToken -Body @{ status = "active"; reason = "" } | Out-Null

    $flags = Invoke-HexbayJson -Method GET `
        -Path "/admin/listing-flags?status=open" -Token $adminToken
    if (-not ($flags.data.flags | Where-Object { [int]$_.id -eq [int]$fixture.flag_id })) {
        throw "Automated listing flag was not visible."
    }
    Invoke-HexbayJson -Method POST `
        -Path "/admin/listing-flags/$($fixture.flag_id)/decision" `
        -Token $adminToken -Body @{
            status = "dismissed"
            note = "Reviewed and dismissed by the integration test."
        } | Out-Null

    Invoke-HexbayJson -Method POST `
        -Path "/admin/complaints/$($fixture.complaint_id)/decision" `
        -Token $adminToken -Body @{
            status = "resolved"
            note = "Resolved safely during the Sprint 2 integration test."
        } | Out-Null

    Invoke-HexbayJson -Method POST `
        -Path "/admin/counterfeit-reports/$($fixture.report_id)/decision" `
        -Token $adminToken -Body @{
            status = "actioned"
            note = "Listing flagged for manual seller follow-up during testing."
        } | Out-Null

    $address = Invoke-HexbayJson -Method POST `
        -Path "/customers/me/addresses" -Token $buyerToken -Body @{
            label = "Home"
            recipient_name = "Sprint Four Buyer"
            phone = "+94 77 123 4567"
            address_line_1 = "42 Buyer Street"
            address_line_2 = "Apartment 4"
            city = "Colombo"
            district = "Colombo"
            postal_code = "00300"
            country_code = "LK"
            is_default = $true
        }
    if (-not [bool]$address.data.address.is_default) {
        throw "The buyer's first delivery address was not made the default."
    }
    Invoke-HexbayJson -Method POST -Path "/wishlist/items" `
        -Token $buyerToken -Body @{ listing_id = $sellerListingId } | Out-Null
    $wishlist = Invoke-HexbayJson -Method GET -Path "/wishlist/items" `
        -Token $buyerToken
    if ($wishlist.data.wishlist.items.Count -ne 1) {
        throw "The buyer wishlist did not preserve its seller offer."
    }
    Invoke-HexbayJson -Method POST -Path "/interactions" `
        -Token $buyerToken -Body @{
            event_type = "search"
            query = "Seller Workspace Test Product"
        } | Out-Null
    Invoke-HexbayJson -Method POST -Path "/interactions" `
        -Token $buyerToken -Body @{
            event_type = "compare"
            product_id = [int]$publicProduct.id
        } | Out-Null
    Invoke-HexbayJson -Method POST -Path "/cart/items" `
        -Token $buyerToken -Body @{
            listing_id = $sellerListingId
            quantity = 1
        } | Out-Null
    $cart = Invoke-HexbayJson -Method POST -Path "/cart/items" `
        -Token $buyerToken -Body @{
            listing_id = $seller2ListingId
            quantity = 2
        }
    if (
        $cart.data.cart.items.Count -ne 2 `
        -or [decimal]$cart.data.cart.summary.subtotal -ne 371000.00
    ) {
        throw "The mixed-vendor cart totals were not calculated from current listing prices."
    }

    $ordersBeforeRollback = Invoke-HexbayJson -Method GET `
        -Path "/orders" -Token $buyerToken
    $seller1StockBeforeRollback = Invoke-HexbayJson -Method GET `
        -Path "/seller/inventory" -Token $sellerToken
    $seller1QuantityBeforeRollback = [int]((
        $seller1StockBeforeRollback.data.inventory |
        Where-Object { [int]$_.listing_id -eq $sellerListingId } |
        Select-Object -First 1
    ).quantity_on_hand)
    Invoke-HexbayJson -Method POST `
        -Path "/seller/inventory/$seller2ListingId/adjust" `
        -Token $seller2Token -Body @{
            quantity_delta = -3
            reason = "Force checkout rollback test"
        } | Out-Null
    $checkoutWasRolledBack = $false
    try {
        Invoke-HexbayJson -Method POST -Path "/orders" `
            -Token $buyerToken -Body @{
                address_id = [int]$address.data.address.id
                payment_method = "card_simulation"
                simulated_payment_acknowledged = $true
                expected_total_lkr = $cart.data.cart.summary.subtotal
                setup_public_ids = @()
            } | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 409) {
            $checkoutWasRolledBack = $true
        } else {
            throw
        }
    }
    if (-not $checkoutWasRolledBack) {
        throw "Checkout did not reject a cart after seller stock changed."
    }
    $ordersAfterRollback = Invoke-HexbayJson -Method GET `
        -Path "/orders" -Token $buyerToken
    if ($ordersAfterRollback.data.orders.Count -ne $ordersBeforeRollback.data.orders.Count) {
        throw "Failed checkout created a partial parent order."
    }
    $seller1StockAfterRollback = Invoke-HexbayJson -Method GET `
        -Path "/seller/inventory" -Token $sellerToken
    $seller1QuantityAfterRollback = [int]((
        $seller1StockAfterRollback.data.inventory |
        Where-Object { [int]$_.listing_id -eq $sellerListingId } |
        Select-Object -First 1
    ).quantity_on_hand)
    if ($seller1QuantityAfterRollback -ne $seller1QuantityBeforeRollback) {
        throw "Failed checkout changed stock for another cart line."
    }
    Invoke-HexbayJson -Method POST `
        -Path "/seller/inventory/$seller2ListingId/adjust" `
        -Token $seller2Token -Body @{
            quantity_delta = 3
            reason = "Restore stock after rollback test"
        } | Out-Null

    $checkout = Invoke-HexbayJson -Method POST -Path "/orders" `
        -Token $buyerToken -Body @{
            address_id = [int]$address.data.address.id
            payment_method = "card_simulation"
            simulated_payment_acknowledged = $true
            expected_total_lkr = $cart.data.cart.summary.subtotal
            setup_public_ids = @()
        }
    $buyerOrder = $checkout.data.order
    if (
        $buyerOrder.sub_orders.Count -ne 2 `
        -or [decimal]$buyerOrder.grand_total -ne 371000.00
    ) {
        throw "Checkout did not create one parent order with two seller sub-orders."
    }
    $seller1SubOrder = $buyerOrder.sub_orders |
        Where-Object { [int]$_.shop_id -eq [int]$publicOffer.shop_id } |
        Select-Object -First 1
    $seller2SubOrder = $buyerOrder.sub_orders |
        Where-Object { [int]$_.shop_id -ne [int]$publicOffer.shop_id } |
        Select-Object -First 1
    $pendingReviewItem = $seller1SubOrder.items | Select-Object -First 1
    $earlyReviewWasBlocked = $false
    try {
        Invoke-HexbayJson -Method POST `
            -Path "/order-items/$($pendingReviewItem.id)/reviews" `
            -Token $buyerToken -Body @{
                rating = 5
                title = "Too early"
                review_text = "This review must be blocked before delivery."
            } | Out-Null
    } catch {
        if ([int]$_.Exception.Response.StatusCode -eq 409) {
            $earlyReviewWasBlocked = $true
        } else {
            throw
        }
    }
    if (-not $earlyReviewWasBlocked) {
        throw "A buyer reviewed an order item before confirming delivery."
    }
    foreach ($transition in @(
        @{ token = $sellerToken; id = $seller1SubOrder.id },
        @{ token = $seller2Token; id = $seller2SubOrder.id }
    )) {
        Invoke-HexbayJson -Method POST `
            -Path "/seller/orders/$($transition.id)/status" `
            -Token $transition.token -Body @{
                status = "processing"
                reason = ""
            } | Out-Null
        foreach ($checkpoint in @("stock_verified", "items_packed", "delivery_address_verified")) {
            Invoke-HexbayJson -Method POST `
                -Path "/seller/orders/$($transition.id)/fulfilment-checkpoints" `
                -Token $transition.token -Body @{ checkpoint_code = $checkpoint } | Out-Null
        }
        Invoke-HexbayJson -Method POST `
            -Path "/seller/orders/$($transition.id)/status" `
            -Token $transition.token -Body @{
                status = "shipped"
                reason = ""
                delivery_method = "third_party_courier"
                delivery_partner = "Smoke Test Courier"
                tracking_reference = "TRACK-$($transition.id)-$($suffix.Substring(0, 8))"
                estimated_delivery_date = (Get-Date).AddDays(5).ToString("yyyy-MM-dd")
                shipment_note = "Automated marketplace smoke-test shipment."
            } | Out-Null
    }
    $confirmedFirst = Invoke-HexbayJson -Method POST `
        -Path "/sub-orders/$($seller1SubOrder.id)/confirm-receipt" `
        -Token $buyerToken
    if ($confirmedFirst.data.order.status -ne "partially_completed") {
        throw "The parent order did not derive a partially-completed status."
    }
    Invoke-HexbayJson -Method POST `
        -Path "/sub-orders/$($seller1SubOrder.id)/confirm-receipt" `
        -Token $buyerToken | Out-Null
    $ledgerAfterRetry = Invoke-HexbayJson -Method GET `
        -Path "/seller/finance" -Token $sellerToken
    $saleEvents = @($ledgerAfterRetry.data.ledger |
        Where-Object { $_.event_key -eq "completion.sale.$($seller1SubOrder.id)" })
    $commissionEvents = @($ledgerAfterRetry.data.ledger |
        Where-Object { $_.event_key -eq "completion.commission.$($seller1SubOrder.id)" })
    if ($saleEvents.Count -ne 1 -or $commissionEvents.Count -ne 1) {
        $completionKeys = ($ledgerAfterRetry.data.ledger |
            Where-Object { $_.event_key -like "completion.*" } |
            ForEach-Object { $_.event_key }) -join ","
        throw "Repeated receipt confirmation ledger counts were sale=$($saleEvents.Count), commission=$($commissionEvents.Count); keys=$completionKeys; sub=$($seller1SubOrder.id)."
    }
    $completedBuyerOrder = Invoke-HexbayJson -Method POST `
        -Path "/sub-orders/$($seller2SubOrder.id)/confirm-receipt" `
        -Token $buyerToken
    if ($completedBuyerOrder.data.order.status -ne "completed") {
        throw "Confirming every seller delivery did not complete the parent order."
    }
    $reviewItem = $completedBuyerOrder.data.order.sub_orders |
        Where-Object { [int]$_.id -eq [int]$seller1SubOrder.id } |
        Select-Object -ExpandProperty items |
        Select-Object -First 1
    $review = Invoke-HexbayJson -Method POST `
        -Path "/order-items/$($reviewItem.id)/reviews" `
        -Token $buyerToken -Body @{
            rating = 5
            title = "Excellent verified purchase"
            review_text = "The product arrived safely and matched the approved listing."
        }
    if (
        -not [bool]$review.data.review.is_verified_purchase `
        -or $review.data.review.status -ne "published"
    ) {
        throw "An eligible completed order item did not create a verified review."
    }
    $complaint = Invoke-HexbayJson -Method POST -Path "/complaints" `
        -Token $buyerToken -Body @{
            order_id = [int]$buyerOrder.id
            sub_order_id = [int]$seller1SubOrder.id
            subject = "Delivery support test"
            description = "Temporary Sprint 4 complaint used to verify the administrator queue."
        }
    $report = Invoke-HexbayJson -Method POST -Path "/counterfeit-reports" `
        -Token $buyerToken -Body @{
            listing_id = $sellerListingId
            order_item_id = [int]$reviewItem.id
            reason_code = "packaging_concern"
            description = "Temporary Sprint 4 authenticity concern for administrator review."
        }
    $buyerNotifications = Invoke-HexbayJson -Method GET `
        -Path "/notifications" -Token $buyerToken
    if (-not ($buyerNotifications.data.notifications |
        Where-Object { $_.type -eq "order_shipped" })) {
        throw "Seller fulfilment did not notify the buyer."
    }
    $newComplaints = Invoke-HexbayJson -Method GET `
        -Path "/admin/complaints?status=open" -Token $adminToken
    $newReports = Invoke-HexbayJson -Method GET `
        -Path "/admin/counterfeit-reports?status=open" -Token $adminToken
    if (
        -not ($newComplaints.data.complaints |
            Where-Object { [int]$_.id -eq [int]$complaint.data.complaint.id }) `
        -or -not ($newReports.data.reports |
            Where-Object { [int]$_.id -eq [int]$report.data.report.id })
    ) {
        throw "Buyer complaint or product report did not reach the administrator queue."
    }

    Invoke-HexbayJson -Method POST -Path "/cart/items" `
        -Token $buyerToken -Body @{
            listing_id = $sellerListingId
            quantity = 1
        } | Out-Null
    $cancelCart = Invoke-HexbayJson -Method GET -Path "/cart" -Token $buyerToken
    $stockBeforeCancellation = Invoke-HexbayJson -Method GET `
        -Path "/seller/inventory" -Token $sellerToken
    $quantityBeforeCancellation = [int]((
        $stockBeforeCancellation.data.inventory |
        Where-Object { [int]$_.listing_id -eq $sellerListingId } |
        Select-Object -First 1
    ).quantity_on_hand)
    $cancelCheckout = Invoke-HexbayJson -Method POST -Path "/orders" `
        -Token $buyerToken -Body @{
            address_id = [int]$address.data.address.id
            payment_method = "card_simulation"
            simulated_payment_acknowledged = $true
            expected_total_lkr = $cancelCart.data.cart.summary.subtotal
            setup_public_ids = @()
        }
    $cancelSubOrder = $cancelCheckout.data.order.sub_orders | Select-Object -First 1
    Invoke-HexbayJson -Method POST `
        -Path "/seller/orders/$($cancelSubOrder.id)/status" `
        -Token $sellerToken -Body @{
            status = "cancelled"
            reason = "Sprint 4 cancellation stock restoration test"
        } | Out-Null
    $stockAfterCancellation = Invoke-HexbayJson -Method GET `
        -Path "/seller/inventory" -Token $sellerToken
    $quantityAfterCancellation = [int]((
        $stockAfterCancellation.data.inventory |
        Where-Object { [int]$_.listing_id -eq $sellerListingId } |
        Select-Object -First 1
    ).quantity_on_hand)
    if ($quantityAfterCancellation -ne $quantityBeforeCancellation) {
        throw "Seller cancellation did not restore the sold inventory exactly once."
    }

    $buyerId = [int]$buyer.data.user.id
    Invoke-HexbayJson -Method POST -Path "/admin/users/$buyerId/status" `
        -Token $adminToken -Body @{
            status = "suspended"
            reason = "Sprint 2 integration authorization test"
        } | Out-Null
    Invoke-HexbayJson -Method POST -Path "/admin/users/$buyerId/status" `
        -Token $adminToken -Body @{
            status = "active"
            reason = ""
        } | Out-Null

    Write-Host "Complete Sprint 2, Sprint 3, and Sprint 4 marketplace smoke test passed."
} finally {
    & $PhpExe -d xdebug.mode=off "backend\tests\cleanup_sprint2_fixture.php"
}
