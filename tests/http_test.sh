#!/data/data/com.termux/files/usr/bin/bash
# End-to-end HTTP tests for the Zellie Fleurs PHP development server.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT="${ZF_TEST_PORT:-8123}"
BASE="http://127.0.0.1:${PORT}"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/zf-http-tests.XXXXXX")"
JAR="$TMP/cookies.txt"
SERVER_LOG="$TMP/server.log"
PASSED=0
FAILED=0
SERVER_PID=""
ISO_PID=""
BROKEN_PID=""

cleanup() {
    for pid in "$SERVER_PID" "$ISO_PID" "$BROKEN_PID"; do
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
            wait "$pid" 2>/dev/null || true
        fi
    done
    chmod 755 "${TMP}/ro" 2>/dev/null || true
    rm -rf "$TMP"
}
trap cleanup EXIT

pass() { PASSED=$((PASSED + 1)); echo "  PASS  $1"; }
fail() { FAILED=$((FAILED + 1)); echo "  FAIL  $1${2:+ — $2}"; }

extract_hidden() {
    php -r '$name=$argv[1];$h=stream_get_contents(STDIN);if(preg_match("/name=\"".preg_quote($name,"/")."\" value=\"([^\"]*)\"/",$h,$m)){echo html_entity_decode($m[1],ENT_QUOTES,"UTF-8");}' "$1"
}

get() { curl -sS -b "$JAR" -c "$JAR" "$BASE$1"; }

status_of() {
    curl -sS -b "$JAR" -c "$JAR" -o "$TMP/body" -w '%{http_code}|%{redirect_url}' "$BASE$1"
}

contains() {
    if grep -Fq "$2" "$1"; then return 0; else return 1; fi
}

echo "Starting test server on port ${PORT}…"
ZF_DB_PATH="$TMP/shop.db" \
ZF_LOG_PATH="$TMP/error.log" \
ZF_ORDER_NOTIFICATION_LOG="$TMP/order-notifications.log" \
ZF_ORDER_RATE_LIMIT=5 \
ZF_ADMIN_USER="admin" \
ZF_ADMIN_PASSWORD="HttpTest123!" \
ZF_DEBUG=0 \
php -S "127.0.0.1:${PORT}" -t "$ROOT/public" "$ROOT/public/router.php" >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
    if curl -sS -o /dev/null "$BASE/" 2>/dev/null; then break; fi
    sleep 0.2
done

echo ""
echo "Storefront"
echo "----------"
CODE=$(curl -sS -o "$TMP/index.html" -w '%{http_code}' "$BASE/")
[ "$CODE" = "200" ] && pass "homepage returns 200" || fail "homepage returns 200" "got $CODE"
contains "$TMP/index.html" "Zellie Fleurs" && pass "branding is present" || fail "branding is present"
contains "$TMP/index.html" "Zellie Signature Blush Roses" && pass "seeded products render from SQLite" || fail "seeded products render from SQLite"
contains "$TMP/index.html" "Add to cart" && pass "add-to-cart buttons render" || fail "add-to-cart buttons render"
contains "$TMP/index.html" "assets/img/products/blush-roses.jpg" && pass "local product photography is used" || fail "local product photography is used"
contains "$TMP/index.html" 'aria-controls="mobile-menu"' && pass "mobile menu trigger is wired for accessibility" || fail "mobile menu trigger is wired for accessibility"
grep -Eq '<nav[^>]*id="mobile-menu"[^>]*hidden' "$TMP/index.html" && pass "mobile navigation is hidden by default" || fail "mobile navigation is hidden by default"
contains "$TMP/index.html" 'style.css?v=20261115-checkout' && pass "versioned stylesheet is served" || fail "versioned stylesheet is served"

curl -sS -o "$TMP/products.json" "$BASE/api/products.php"
PRODUCT_ID=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["products"][0]["id"] ?? "";' "$TMP/products.json")
PRODUCT_NAME=$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d["products"][0]["name"] ?? "";' "$TMP/products.json")
[ -n "$PRODUCT_ID" ] && pass "products API returns JSON data" || fail "products API returns JSON data"

SEARCH_CODE=$(curl -sS -o "$TMP/search.html" -w '%{http_code}' "$BASE/index.php?q=tulip")
[ "$SEARCH_CODE" = "200" ] && contains "$TMP/search.html" "Amethyst Dream Tulips" && pass "server-side product search works" || fail "server-side product search works"
curl -sS -o "$TMP/category.html" "$BASE/index.php?category=Luxury%20Boxes"
contains "$TMP/category.html" "Solstice Sunflower Box" && pass "category filter works" || fail "category filter works"

echo ""
echo "Admin authentication"
echo "--------------------"
AUTH_STATUS=$(status_of "/admin/index.php")
case "$AUTH_STATUS" in
    303*login.php*) pass "unauthorized admin page redirects to login" ;;
    *) fail "unauthorized admin page redirects to login" "$AUTH_STATUS" ;;
esac

curl -sS -b "$JAR" -c "$JAR" -o "$TMP/login.html" "$BASE/admin/login.php"
CSRF=$(extract_hidden csrf_token < "$TMP/login.html")
[ -n "$CSRF" ] && pass "login form includes CSRF token" || fail "login form includes CSRF token"

WRONG=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/wrong.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=wrong-password" \
    "$BASE/admin/login.php")
[ "$WRONG" = "200" ] && contains "$TMP/wrong.html" "Invalid username or password" && pass "invalid login is rejected" || fail "invalid login is rejected" "status $WRONG"

LOGIN=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/login-response.html" -D "$TMP/login-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=HttpTest123!" \
    "$BASE/admin/login.php")
[ "$LOGIN" = "303" ] && pass "valid login redirects to dashboard" || fail "valid login redirects to dashboard" "status $LOGIN"

DASH=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/dashboard.html" -w '%{http_code}' "$BASE/admin/index.php")
[ "$DASH" = "200" ] && contains "$TMP/dashboard.html" "Total revenue" && pass "dashboard loads after login" || fail "dashboard loads after login" "status $DASH"

# Quick actions, empty state, and studio note must render even with zero orders
contains "$TMP/dashboard.html" "Keep the studio moving" && pass "quick actions render on empty dashboard" || fail "quick actions render on empty dashboard"
contains "$TMP/dashboard.html" "No orders yet" && pass "empty state renders when there are no orders" || fail "empty state renders when there are no orders"
contains "$TMP/dashboard.html" "Orders, handled with care" && pass "studio note renders on empty dashboard" || fail "studio note renders on empty dashboard"

echo ""
echo "Product management"
echo "------------------"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/admin-products.html" "$BASE/admin/products.php"
CSRF=$(extract_hidden csrf_token < "$TMP/admin-products.html")
ADD=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/add-product.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "action=add" \
    --data-urlencode "name=Http Test Orchid" \
    --data-urlencode "category=Bouquets" \
    --data-urlencode "price=999.00" \
    --data-urlencode "description=A test orchid arrangement for the HTTP test suite." \
    --data-urlencode "stock_quantity=3" \
    --data-urlencode "image_url=assets/img/products/rosy-pastel.jpg" \
    --data-urlencode "is_active=1" \
    "$BASE/admin/products.php")
[ "$ADD" = "303" ] && pass "product creation works" || fail "product creation works" "status $ADD"
curl -sS -o "$TMP/new-product.html" "$BASE/index.php?q=Http%20Test%20Orchid"
contains "$TMP/new-product.html" "Http Test Orchid" && pass "new product appears on storefront" || fail "new product appears on storefront"

# Real image upload through multipart form
php -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII="));' "$TMP/upload.png"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/admin-products2.html" "$BASE/admin/products.php"
CSRF=$(extract_hidden csrf_token < "$TMP/admin-products2.html")
UPLOAD=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/upload-product.html" -w '%{http_code}' \
    -F "csrf_token=$CSRF" \
    -F "action=add" \
    -F "name=Http Upload Bloom" \
    -F "category=Bouquets" \
    -F "price=750.00" \
    -F "description=A product created by the upload test." \
    -F "stock_quantity=2" \
    -F "is_active=1" \
    -F "image=@$TMP/upload.png;type=image/png" \
    "$BASE/admin/products.php")
UPLOADED_PATH=$(sqlite3 "$TMP/shop.db" "SELECT image_url FROM products WHERE name = 'Http Upload Bloom' LIMIT 1;" 2>/dev/null)
[ "$UPLOAD" = "303" ] && [ -n "$UPLOADED_PATH" ] && [ -f "$ROOT/public/$UPLOADED_PATH" ] && pass "image upload works and is stored safely" || fail "image upload works and is stored safely" "status $UPLOAD path $UPLOADED_PATH"

ORCHID_ID=$(sqlite3 "$TMP/shop.db" "SELECT id FROM products WHERE name = 'Http Test Orchid' LIMIT 1;")
TOGGLE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "action=toggle" \
    --data-urlencode "id=$ORCHID_ID" \
    "$BASE/admin/products.php")
curl -sS -o "$TMP/inactive.html" "$BASE/index.php?q=Http%20Test%20Orchid"
[ "$TOGGLE" = "303" ] && ! grep -Fq "data-product-id=\"$ORCHID_ID\"" "$TMP/inactive.html" && pass "product deactivation hides it from storefront" || fail "product deactivation hides it from storefront"

DELETE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "action=delete" \
    --data-urlencode "id=$ORCHID_ID" \
    "$BASE/admin/products.php")
[ "$DELETE" = "303" ] && [ -z "$(sqlite3 "$TMP/shop.db" "SELECT id FROM products WHERE id = $ORCHID_ID;")" ] && pass "unreferenced product can be deleted" || fail "unreferenced product can be deleted"

echo ""
echo "Checkout and tracking"
echo "---------------------"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout.html" "$BASE/checkout.php"
CSRF=$(extract_hidden csrf_token < "$TMP/checkout.html")
CHECKOUT_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout.html")
[ -n "$CSRF" ] && [ -n "$CHECKOUT_TOKEN" ] && pass "checkout form includes CSRF and idempotency tokens" || fail "checkout form includes CSRF and idempotency tokens"

# Same-day delivery cutoff note: the cutoff (orders for TODAY are rejected at
# or after 12:00 PM shop time, see ZF_SAME_DAY_CUTOFF_HOUR in src/orders.php)
# cannot be forced from this harness — it runs on the real wall clock. That
# is why every checkout POST below uses a FUTURE delivery date (+3 days): the
# suite must pass whether it runs at 09:00 or 17:00. The cutoff itself is
# covered deterministically by the "Same-day delivery cutoff" section in
# tests/run.php via the $options['now'] clock override.
DELIVERY_DATE=$(date -d '+3 days' '+%Y-%m-%d' 2>/dev/null || date -v+3d '+%Y-%m-%d')
CHECKOUT=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-response.html" -D "$TMP/checkout-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "checkout_token=$CHECKOUT_TOKEN" \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":2}]" \
    --data-urlencode "customer_name=Http Test Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "card_message=For the HTTP test" \
    --data-urlencode "payment_method=COD" \
    --data-urlencode "gcash_ref=" \
    "$BASE/checkout.php")
ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-HJ-NP-Z2-9]{12}/",$m[1],$c)) echo $c[0];' "$TMP/checkout-headers.txt")
[ "$CHECKOUT" = "303" ] && [ -n "$ORDER_CODE" ] && pass "valid order is created and redirects to confirmation" || fail "valid order is created and redirects to confirmation" "status $CHECKOUT code $ORDER_CODE"

CONFIRM=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/confirmation.html" -w '%{http_code}' "$BASE/checkout.php?order=$ORDER_CODE")
[ "$CONFIRM" = "200" ] && contains "$TMP/confirmation.html" "$ORDER_CODE" && contains "$TMP/confirmation.html" "Order received" && pass "confirmation screen shows the reference" || fail "confirmation screen shows the reference"

curl -sS -o "$TMP/track.html" "$BASE/track.php?code=$ORDER_CODE"
contains "$TMP/track.html" "$ORDER_CODE" && contains "$TMP/track.html" "Pending" && pass "tracking page returns the correct order" || fail "tracking page returns the correct order"
curl -sS -o "$TMP/track-invalid.html" "$BASE/track.php?code=ZF-NOPE00"
contains "$TMP/track-invalid.html" "No order found" && pass "invalid tracking code shows a friendly message" || fail "invalid tracking code shows a friendly message"

# Price manipulation must not affect the stored order total
STORED_TOTAL=$(sqlite3 "$TMP/shop.db" "SELECT printf('%.2f', total_amount) FROM orders WHERE order_code = '$ORDER_CODE';")
EXPECTED_TOTAL=$(sqlite3 "$TMP/shop.db" "SELECT printf('%.2f', price * 2) FROM products WHERE id = $PRODUCT_ID;")
[ "$STORED_TOTAL" = "$EXPECTED_TOTAL" ] && pass "server ignores manipulated client totals" || fail "server ignores manipulated client totals" "stored $STORED_TOTAL expected $EXPECTED_TOTAL"

# Duplicate POST with the same idempotency token must not create a second order
BEFORE_COUNT=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "checkout_token=$CHECKOUT_TOKEN" \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":2}]" \
    --data-urlencode "customer_name=Http Test Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php" >/dev/null
AFTER_COUNT=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
[ "$BEFORE_COUNT" = "$AFTER_COUNT" ] && pass "duplicate submission is prevented" || fail "duplicate submission is prevented" "before $BEFORE_COUNT after $AFTER_COUNT"

echo ""
echo "Order management"
echo "----------------"
ORDER_ID=$(sqlite3 "$TMP/shop.db" "SELECT id FROM orders WHERE order_code = '$ORDER_CODE';")
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/admin-orders.html" "$BASE/admin/orders.php"

# Delivery and Items columns render in the desktop orders table
DELIVERY_RENDERED=$(date -d "$DELIVERY_DATE" '+%b %-d, %Y' 2>/dev/null || date -j -f '%Y-%m-%d' "$DELIVERY_DATE" '+%b %-d, %Y' 2>/dev/null)
[ -n "$DELIVERY_RENDERED" ] && contains "$TMP/admin-orders.html" "$DELIVERY_RENDERED" && pass "orders listing shows the delivery date" || fail "orders listing shows the delivery date" "expected '$DELIVERY_RENDERED'"
contains "$TMP/admin-orders.html" "2 × $PRODUCT_NAME" && pass "orders listing shows product name × qty" || fail "orders listing shows product name × qty"
contains "$TMP/admin-orders.html" "<th scope=\"col\">Delivery</th>" && contains "$TMP/admin-orders.html" "<th scope=\"col\">Items</th>" && pass "orders table has Delivery and Items column headers" || fail "orders table has Delivery and Items column headers"

CSRF=$(extract_hidden csrf_token < "$TMP/admin-orders.html")
UPDATE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    --data-urlencode "update_status=1" \
    --data-urlencode "order_id=$ORDER_ID" \
    --data-urlencode "status=Confirmed" \
    "$BASE/admin/orders.php")
curl -sS -o "$TMP/track-confirmed.html" "$BASE/track.php?code=$ORDER_CODE"
[ "$UPDATE" = "303" ] && contains "$TMP/track-confirmed.html" "Confirmed" && pass "admin status update appears on customer tracking" || fail "admin status update appears on customer tracking"

DETAIL=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/order-detail.html" -w '%{http_code}' "$BASE/admin/view_order.php?id=$ORDER_ID")
[ "$DETAIL" = "200" ] && contains "$TMP/order-detail.html" "Order invoice" && contains "$TMP/order-detail.html" "Http Test Customer" && pass "admin can view full order details" || fail "admin can view full order details"

echo ""
echo "Admin pagination"
echo "----------------"
# Seed 25 orders (20 Pending, 5 Confirmed) with staggered timestamps: j=25 is
# the newest seed, j=1 the oldest. Together with the single real order above
# the admin list has 26 orders = 2 pages of 20 + 6.
PAG_ORD_SQL=""
for j in $(seq 1 25); do
    PAG_CODE=$(printf 'ZF-PAG-%04d' "$j")
    PAG_STATUS="Pending"
    [ "$j" -gt 20 ] && PAG_STATUS="Confirmed"
    PAG_MINS=$((26 - j))
    PAG_ORD_SQL+="INSERT INTO orders (order_code,customer_name,contact_number,delivery_address,delivery_date,payment_method,gcash_ref,total_amount,items_json,status,created_at,updated_at) VALUES ('$PAG_CODE','Pagination Seed $j','09170000$(printf '%04d' "$j")','1 Pag Street, Test City','2027-01-20','GCash','PAGREF$j',500.0,'[]','$PAG_STATUS',datetime('now','localtime','-$PAG_MINS minutes'),datetime('now','localtime','-$PAG_MINS minutes'));"
done
sqlite3 "$TMP/shop.db" "$PAG_ORD_SQL"

PAG_ORD_TOTAL=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
PAG_ORD_PAGES=$(( (PAG_ORD_TOTAL + 19) / 20 ))
[ "$PAG_ORD_TOTAL" = "26" ] && pass "pagination seed produced 26 orders" || fail "pagination seed produced 26 orders" "got $PAG_ORD_TOTAL"

curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-1.html" "$BASE/admin/orders.php"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-2.html" "$BASE/admin/orders.php?page=2"

contains "$TMP/pag-orders-1.html" "Page 1 of $PAG_ORD_PAGES" && pass "orders first page shows page status" || fail "orders first page shows page status"
contains "$TMP/pag-orders-1.html" "Showing 1–20 of $PAG_ORD_TOTAL orders" && pass "orders first page shows record summary" || fail "orders first page shows record summary"
contains "$TMP/pag-orders-2.html" "Page 2 of $PAG_ORD_PAGES" && pass "orders second page shows page status" || fail "orders second page shows page status"
contains "$TMP/pag-orders-2.html" "Showing 21–$PAG_ORD_TOTAL of $PAG_ORD_TOTAL orders" && pass "orders second page shows record summary" || fail "orders second page shows record summary"
contains "$TMP/pag-orders-2.html" "ZF-PAG-0001" && ! contains "$TMP/pag-orders-2.html" "ZF-PAG-0007" && pass "orders pages split without duplicates (oldest rows on page 2)" || fail "orders pages split without duplicates (oldest rows on page 2)"
contains "$TMP/pag-orders-1.html" "ZF-PAG-0025" && ! contains "$TMP/pag-orders-1.html" "ZF-PAG-0001" && pass "newest-first ordering puts recent orders on page 1" || fail "newest-first ordering puts recent orders on page 1"
contains "$TMP/pag-orders-1.html" "aria-disabled=\"true\"" && pass "Previous control disabled on first page" || fail "Previous control disabled on first page"
PAG_NEXT=$(grep -o 'rel="next" href="[^"]*"' "$TMP/pag-orders-1.html" | head -1)
case "$PAG_NEXT" in *page=2*) pass "Next control links to page 2" ;; *) fail "Next control links to page 2" "$PAG_NEXT" ;; esac
contains "$TMP/pag-orders-2.html" "aria-disabled=\"true\"" && pass "Next control disabled on last page" || fail "Next control disabled on last page"

curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-far.html" "$BASE/admin/orders.php?page=999"
contains "$TMP/pag-orders-far.html" "Page $PAG_ORD_PAGES of $PAG_ORD_PAGES" && pass "out-of-range page clamps to last page" || fail "out-of-range page clamps to last page"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-junk.html" "$BASE/admin/orders.php?page=abc"
contains "$TMP/pag-orders-junk.html" "Page 1 of $PAG_ORD_PAGES" && pass "invalid page value falls back to first page" || fail "invalid page value falls back to first page"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-neg.html" "$BASE/admin/orders.php?page=-3"
contains "$TMP/pag-orders-neg.html" "Page 1 of $PAG_ORD_PAGES" && pass "negative page value falls back to first page" || fail "negative page value falls back to first page"

# Search + sort preserved through pagination links
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-search.html" "$BASE/admin/orders.php?q=ZF-PAG-00&sort=oldest"
contains "$TMP/pag-orders-search.html" "Page 1 of 2" && contains "$TMP/pag-orders-search.html" "Showing 1–20 of 25 orders" && pass "search narrows the paginated listing" || fail "search narrows the paginated listing"
PAG_SEARCH_NEXT=$(grep -o 'rel="next" href="[^"]*"' "$TMP/pag-orders-search.html" | head -1)
case "$PAG_SEARCH_NEXT" in *q=ZF-PAG-00*sort=oldest*page=2*) pass "pagination link preserves search and sort parameters" ;; *) fail "pagination link preserves search and sort parameters" "$PAG_SEARCH_NEXT" ;; esac

# Status filter combined with pagination
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-orders-pending.html" "$BASE/admin/orders.php?status=Pending"
contains "$TMP/pag-orders-pending.html" "Showing 1–20 of 20 orders" && contains "$TMP/pag-orders-pending.html" "Page 1 of 1" && pass "status filter combined with pagination" || fail "status filter combined with pagination"

# POST status update still works while viewing a later page
PAG_CSRF=$(extract_hidden csrf_token < "$TMP/pag-orders-2.html")
PAG_UPDATE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$PAG_CSRF" \
    --data-urlencode "update_status=1" \
    --data-urlencode "order_id=$ORDER_ID" \
    --data-urlencode "status=Delivered" \
    "$BASE/admin/orders.php?page=2")
PAG_NEW_STATUS=$(sqlite3 "$TMP/shop.db" "SELECT status FROM orders WHERE id = $ORDER_ID;")
[ "$PAG_UPDATE" = "303" ] && [ "$PAG_NEW_STATUS" = "Delivered" ] && pass "status update POST works while paginated" || fail "status update POST works while paginated" "status $PAG_UPDATE order status $PAG_NEW_STATUS"

# Seed 25 products so the catalogue crosses a page boundary
PAG_PROD_SQL=""
for j in $(seq 1 25); do
    PAG_NAME="Pagination Product $(printf '%02d' "$j")"
    PAG_PROD_SQL+="INSERT INTO products (name,category,price,description,image_url,stock_quantity,is_active) VALUES ('$PAG_NAME','Bouquets',150.0,'Seed product for pagination tests.','assets/img/products/rosy-pastel.jpg',5,1);"
done
sqlite3 "$TMP/shop.db" "$PAG_PROD_SQL"

PAG_PROD_TOTAL=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM products;")
PAG_PROD_PAGES=$(( (PAG_PROD_TOTAL + 19) / 20 ))
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-products-1.html" "$BASE/admin/products.php"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-products-2.html" "$BASE/admin/products.php?page=2"

contains "$TMP/pag-products-1.html" "Page 1 of $PAG_PROD_PAGES" && pass "products first page shows page status" || fail "products first page shows page status"
contains "$TMP/pag-products-2.html" "Page 2 of $PAG_PROD_PAGES" && pass "products second page shows page status" || fail "products second page shows page status"
contains "$TMP/pag-products-2.html" "Showing 21–$PAG_PROD_TOTAL of $PAG_PROD_TOTAL products" && pass "products second page shows record summary" || fail "products second page shows record summary"
contains "$TMP/pag-products-2.html" "Pagination Product 01" && ! contains "$TMP/pag-products-2.html" "Pagination Product 25" && pass "products pages split without duplicates" || fail "products pages split without duplicates"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/pag-products-far.html" "$BASE/admin/products.php?page=500"
contains "$TMP/pag-products-far.html" "Page $PAG_PROD_PAGES of $PAG_PROD_PAGES" && pass "products out-of-range page clamps to last page" || fail "products out-of-range page clamps to last page"
contains "$TMP/pag-products-2.html" 'admin/products.php?edit=' && pass "edit links preserved on paginated product list" || fail "edit links preserved on paginated product list"

# Product toggle still works from a paginated view
PAG_TOGGLE_ID=$(sqlite3 "$TMP/shop.db" "SELECT id FROM products WHERE name = 'Pagination Product 05' LIMIT 1;")
PAG_TOGGLE=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$PAG_CSRF" \
    --data-urlencode "action=toggle" \
    --data-urlencode "id=$PAG_TOGGLE_ID" \
    "$BASE/admin/products.php?page=2")
[ "$PAG_TOGGLE" = "303" ] && pass "product toggle POST works while paginated" || fail "product toggle POST works while paginated" "status $PAG_TOGGLE"

echo ""
echo "Logout and security"
echo "-------------------"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/admin-products3.html" "$BASE/admin/products.php"
CSRF=$(extract_hidden csrf_token < "$TMP/admin-products3.html")
LOGOUT=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$CSRF" \
    "$BASE/admin/logout.php")
AFTER_LOGOUT=$(status_of "/admin/index.php")
[ "$LOGOUT" = "303" ] && case "$AFTER_LOGOUT" in 303*login.php*) pass "logout works and admin pages are protected" ;; *) fail "logout works and admin pages are protected" "$AFTER_LOGOUT" ;; esac || fail "logout works and admin pages are protected" "status $LOGOUT"

CSRF_MISSING=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/no-csrf.html" -w '%{http_code}' \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":1}]" \
    --data-urlencode "customer_name=No CSRF" \
    "$BASE/checkout.php")
[ "$CSRF_MISSING" = "200" ] && contains "$TMP/no-csrf.html" "security token" && pass "checkout rejects missing CSRF token" || fail "checkout rejects missing CSRF token" "status $CSRF_MISSING"

if [ -n "${UPLOADED_PATH:-}" ] && [ -f "$ROOT/public/$UPLOADED_PATH" ]; then
    rm -f "$ROOT/public/$UPLOADED_PATH"
fi

# ---------------------------------------------------------------------------
# Checkout bot guard (honeypot + speed guard), appended assertions.
# Order counts are read from the isolated ZF_DB_PATH test DB in $TMP/shop.db.
# ---------------------------------------------------------------------------
echo ""
echo "Checkout bot guard"
echo "------------------"

# Fetch a fresh checkout page: this re-establishes the session render
# timestamp consumed by earlier checkout POSTs and captures fresh tokens.
BOT_GET=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-bot.html" -w '%{http_code}' "$BASE/checkout.php")
[ "$BOT_GET" = "200" ] && contains "$TMP/checkout-bot.html" 'name="website"' && pass "checkout page returns 200 and includes the honeypot field" || fail "checkout page returns 200 and includes the honeypot field" "status $BOT_GET"
[ "$BOT_GET" = "200" ] && contains "$TMP/checkout-bot.html" 'name="form_loaded_at"' && pass "checkout page includes the speed-guard render token" || fail "checkout page includes the speed-guard render token" "status $BOT_GET"

BOT_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-bot.html")
BOT_LOADED=$(extract_hidden form_loaded_at < "$TMP/checkout-bot.html")
BOT_CHECKOUT_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-bot.html")
[ -n "$BOT_CSRF" ] && [ -n "$BOT_LOADED" ] && [ -n "$BOT_CHECKOUT_TOKEN" ] && pass "bot-guard tokens are extractable" || fail "bot-guard tokens are extractable" "csrf '$BOT_CSRF' loaded '$BOT_LOADED' token '$BOT_CHECKOUT_TOKEN'"

BEFORE_HONEYPOT=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
HONEYPOT=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/honeypot-response.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$BOT_CSRF" \
    --data-urlencode "checkout_token=$BOT_CHECKOUT_TOKEN" \
    --data-urlencode "form_loaded_at=$BOT_LOADED" \
    --data-urlencode "website=http://spam.example" \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Bot Visitor" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php")
AFTER_HONEYPOT=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
[ "$HONEYPOT" = "200" ] && contains "$TMP/honeypot-response.html" "We could not place your order" && pass "honeypot submission is rejected with a generic error" || fail "honeypot submission is rejected with a generic error" "status $HONEYPOT"
[ "$BEFORE_HONEYPOT" = "$AFTER_HONEYPOT" ] && pass "honeypot submission does not create an order" || fail "honeypot submission does not create an order" "before $BEFORE_HONEYPOT after $AFTER_HONEYPOT"

# Speed guard: fetch a fresh checkout page (stores a new render timestamp in
# the session) and POST immediately — well under the 2-second threshold.
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-fast.html" "$BASE/checkout.php"
FAST_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-fast.html")
FAST_LOADED=$(extract_hidden form_loaded_at < "$TMP/checkout-fast.html")
FAST_CHECKOUT_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-fast.html")
BEFORE_FAST=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
FAST=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/fast-response.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$FAST_CSRF" \
    --data-urlencode "checkout_token=$FAST_CHECKOUT_TOKEN" \
    --data-urlencode "form_loaded_at=$FAST_LOADED" \
    --data-urlencode "website=" \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Impatient Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php")
AFTER_FAST=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
if [ "$FAST" = "200" ] && contains "$TMP/fast-response.html" "We could not place your order"; then
    pass "sub-second submission with a valid token is rejected"
else
    fail "sub-second submission with a valid token is rejected" "status $FAST"
fi
[ "$BEFORE_FAST" = "$AFTER_FAST" ] && pass "sub-second submission does not create an order" || fail "sub-second submission does not create an order" "before $BEFORE_FAST after $AFTER_FAST"

echo ""
echo "Log hygiene"
echo "-----------"
if grep -Eq 'PHP (Warning|Fatal error|Parse error)' "$SERVER_LOG"; then
    fail "server log contains no PHP warnings or fatal errors"
    grep -E 'PHP (Warning|Fatal error|Parse error)' "$SERVER_LOG" | head -5
else
    pass "server log contains no PHP warnings or fatal errors"
fi

# ---------------------------------------------------------------------------
# Appended coverage. Seed data below is inserted ONLY into the isolated
# harness DB ($TMP/shop.db); production data/shop.db is never touched here.
# ---------------------------------------------------------------------------
echo ""
echo "Seeded probe data (isolated harness DB)"
echo "---------------------------------------"
sqlite3 "$TMP/shop.db" <<'EOF'
INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active)
  VALUES ('Percent Probe Rose', 'Bouquets', 500.0,
          'Contains 50% more petals than our classic rose and a 100_ sunshine rating.',
          'assets/img/products/rosy-pastel.jpg', 5, 1);
INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active)
  VALUES ('Http Inactive Bloom', 'Bouquets', 700.0,
          'An inactive product hidden from the storefront by the test suite.',
          'assets/img/products/rosy-pastel.jpg', 9, 0);
INSERT INTO products (name, category, price, description, image_url, stock_quantity, is_active)
  VALUES ('Http Sold Out Bloom', 'Bouquets', 890.0,
          'An active arrangement with zero stock, added by the test suite.',
          'assets/img/products/rosy-pastel.jpg', 0, 1);
EOF
INACTIVE_ID=$(sqlite3 "$TMP/shop.db" "SELECT id FROM products WHERE name = 'Http Inactive Bloom';")
SOLDOUT_ID=$(sqlite3 "$TMP/shop.db" "SELECT id FROM products WHERE name = 'Http Sold Out Bloom';")
[ -n "$INACTIVE_ID" ] && [ -n "$SOLDOUT_ID" ] && pass "probe products inserted into harness DB only" || fail "probe products inserted into harness DB only" "inactive '$INACTIVE_ID' soldout '$SOLDOUT_ID'"

echo ""
echo "Product detail page"
echo "-------------------"
P1_CODE=$(curl -sS -o "$TMP/product-1.html" -w '%{http_code}' "$BASE/product.php?id=1")
if [ "$P1_CODE" = "200" ]; then pass "product.php?id=1 returns 200"; else fail "product.php?id=1 returns 200" "got $P1_CODE"; fi
contains "$TMP/product-1.html" "Zellie Signature Blush Roses" && pass "product page shows product name" || fail "product page shows product name"
contains "$TMP/product-1.html" "₱1,899.00" && pass "product page shows price" || fail "product page shows price"
contains "$TMP/product-1.html" "Hand-tied blush and ivory garden roses" && pass "product page shows description" || fail "product page shows description"
contains "$TMP/product-1.html" 'data-add-to-cart' && pass "product page shows add button" || fail "product page shows add button"
contains "$TMP/product-1.html" 'data-qty-input' && contains "$TMP/product-1.html" 'data-qty-inc' && pass "product page shows quantity stepper" || fail "product page shows quantity stepper"
contains "$TMP/product-1.html" 'application/ld+json' && pass "product page embeds JSON-LD" || fail "product page embeds JSON-LD"
contains "$TMP/product-1.html" '"price":"1899.00"' && pass "JSON-LD price is 1899.00" || fail "JSON-LD price is 1899.00"
contains "$TMP/product-1.html" '"priceCurrency":"PHP"' && pass "JSON-LD priceCurrency is PHP" || fail "JSON-LD priceCurrency is PHP"
contains "$TMP/product-1.html" '"availability":"https://schema.org/InStock"' && pass "JSON-LD availability is InStock" || fail "JSON-LD availability is InStock"
contains "$TMP/product-1.html" '"itemCondition":"https://schema.org/NewCondition"' && pass "JSON-LD itemCondition is NewCondition" || fail "JSON-LD itemCondition is NewCondition"
grep -Eq 'class="related-products"' "$TMP/product-1.html" && \
    grep -Eq 'href="/product\.php\?id=[0-9]+"' "$TMP/product-1.html" && \
    pass "related products link to product.php" || fail "related products link to product.php"

for BAD_ID in 999 abc 0 "$INACTIVE_ID"; do
    BAD_CODE=$(curl -sS -o "$TMP/product-bad-$RANDOM.html" -w '%{http_code}' "$BASE/product.php?id=$BAD_ID")
    [ "$BAD_CODE" = "404" ] && pass "product.php?id=$BAD_ID returns 404" || fail "product.php?id=$BAD_ID returns 404" "got $BAD_CODE"
done

SOLDOUT_CODE=$(curl -sS -o "$TMP/product-soldout.html" -w '%{http_code}' "$BASE/product.php?id=$SOLDOUT_ID")
if [ "$SOLDOUT_CODE" = "200" ]; then pass "sold-out product page returns 200"; else fail "sold-out product page returns 200" "got $SOLDOUT_CODE"; fi
grep -Eq 'product-badge--out' "$TMP/product-soldout.html" && pass "sold-out badge renders" || fail "sold-out badge renders"
grep -Eq 'data-product-stock="0"' "$TMP/product-soldout.html" && \
    grep -E 'class="btn btn--add"' -A5 "$TMP/product-soldout.html" | grep -q 'disabled' && \
    pass "sold-out product shows disabled add button" || fail "sold-out product shows disabled add button"
contains "$TMP/product-soldout.html" '"availability":"https://schema.org/OutOfStock"' && pass "sold-out JSON-LD availability is OutOfStock" || fail "sold-out JSON-LD availability is OutOfStock"
grep -Eq 'data-qty-inc[^>]* disabled' "$TMP/product-soldout.html" && pass "sold-out product disables quantity stepper" || fail "sold-out product disables quantity stepper"

echo ""
echo "Cart page"
echo "---------"
CART_CODE=$(curl -sS -o "$TMP/cart.html" -w '%{http_code}' "$BASE/cart.php")
if [ "$CART_CODE" = "200" ]; then pass "cart.php returns 200"; else fail "cart.php returns 200" "got $CART_CODE"; fi
contains "$TMP/cart.html" 'data-cart-page-items' && pass "cart page mounts items container" || fail "cart page mounts items container"
contains "$TMP/cart.html" 'data-cart-page-subtotal' && pass "cart page mounts subtotal element" || fail "cart page mounts subtotal element"
contains "$TMP/cart.html" 'data-cart-page-link' && pass "cart page links to checkout" || fail "cart page links to checkout"
awk '/<noscript>/,/<\/noscript>/' "$TMP/cart.html" > "$TMP/cart-noscript.txt"
if grep -Fq "message the studio" "$TMP/cart-noscript.txt" && \
   { grep -Fq 'tel:' "$TMP/cart-noscript.txt" || grep -Fq "No contact details published yet" "$TMP/cart-noscript.txt"; }; then
    pass "cart page noscript block references contact info"
else
    fail "cart page noscript block references contact info"
fi

# Checkout page must carry the same no-JS manual-order path: settings-driven
# contact details instead of an unopenable JS-only form loop.
curl -sS -o "$TMP/checkout-noscript.html" "$BASE/checkout.php"
awk '/<noscript>/,/<\/noscript>/' "$TMP/checkout-noscript.html" > "$TMP/checkout-noscript.txt"
if grep -Fq "message the studio" "$TMP/checkout-noscript.txt" && \
   { grep -Fq 'tel:' "$TMP/checkout-noscript.txt" || grep -Fq "No contact details published yet" "$TMP/checkout-noscript.txt"; }; then
    pass "checkout page noscript block references contact info"
else
    fail "checkout page noscript block references contact info"
fi
if grep -Fq "JavaScript turned off" "$TMP/checkout-noscript.txt"; then
    pass "checkout page noscript explains JavaScript is required"
else
    fail "checkout page noscript explains JavaScript is required"
fi

echo ""
echo "Checkout bot-guards and edge-case validation"
echo "--------------------------------------------"
# Honeypot field must be visually hidden via its inline style
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-hidden.html" "$BASE/checkout.php"
contains "$TMP/checkout-hidden.html" 'left:-9999px' && pass "honeypot field is visually hidden with inline style" || fail "honeypot field is visually hidden with inline style"

# Same-day cutoff: use a future date (+3 days), never today — see the note
# above DELIVERY_DATE; the cutoff is covered by tests/run.php.
GOOD_DATE=$(date -d '+3 days' '+%Y-%m-%d' 2>/dev/null || date -v+3d '+%Y-%m-%d')
FAR_DATE=$(date -d '+181 days' '+%Y-%m-%d' 2>/dev/null || date -v+181d '+%Y-%m-%d')

# Positive bot-guard path: fresh GET provides form_loaded_at, user waits well
# over 2 seconds, no honeypot fill, valid tokens — order must be created.
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-good.html" "$BASE/checkout.php"
GOOD_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-good.html")
GOOD_LOADED=$(extract_hidden form_loaded_at < "$TMP/checkout-good.html")
GOOD_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-good.html")
sleep 3.5
STOCK3_BEFORE=$(sqlite3 "$TMP/shop.db" "SELECT stock_quantity FROM products WHERE id = 3;")
GOOD=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/good-response.html" -D "$TMP/good-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$GOOD_CSRF" \
    --data-urlencode "checkout_token=$GOOD_TOKEN" \
    --data-urlencode "form_loaded_at=$GOOD_LOADED" \
    --data-urlencode "website=" \
    --data-urlencode "items_json=[{\"id\":3,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Patient Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$GOOD_DATE" \
    --data-urlencode "card_message=Slow and steady" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php")
GOOD_ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-HJ-NP-Z2-9]{12}/",$m[1],$c)) echo $c[0];' "$TMP/good-headers.txt")
[ "$GOOD" = "303" ] && [ -n "$GOOD_ORDER_CODE" ] && pass ">2s submit with valid token creates an order" || fail ">2s submit with valid token creates an order" "status $GOOD code $GOOD_ORDER_CODE"
STOCK3_AFTER=$(sqlite3 "$TMP/shop.db" "SELECT stock_quantity FROM products WHERE id = 3;")
[ "$STOCK3_AFTER" = "$((STOCK3_BEFORE - 1))" ] && pass "order creation deducted stock" || fail "order creation deducted stock" "before $STOCK3_BEFORE after $STOCK3_AFTER"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/good-confirm.html" "$BASE/checkout.php?order=$GOOD_ORDER_CODE"
contains "$TMP/good-confirm.html" "$GOOD_ORDER_CODE" && pass "redirect lands on working confirmation page" || fail "redirect lands on working confirmation page"
curl -sS -o "$TMP/good-track.html" "$BASE/track.php?code=$GOOD_ORDER_CODE"
contains "$TMP/good-track.html" "$GOOD_ORDER_CODE" && contains "$TMP/good-track.html" "Pending" && pass "order is visible on track.php" || fail "order is visible on track.php"

# Validation edge cases: each must return a 200 page with its specific error
# and no order may be created across the whole batch.
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-edge.html" "$BASE/checkout.php"
EDGE_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-edge.html")
EDGE_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-edge.html")
BEFORE_EDGE=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
EDGE_STOCK3=$(sqlite3 "$TMP/shop.db" "SELECT stock_quantity FROM products WHERE id = 3;")
LONG_MSG=$(php -r 'echo str_repeat("x", 301);')

edge_post() {
    # $1 items_json, $2 output file, $3..$8 optional form fields
    local msg="${6:-}" pay="${7:-COD}" gr="${8:-}"
    curl -sS -b "$JAR" -c "$JAR" -o "$2" -w '%{http_code}' \
        --data-urlencode "csrf_token=$EDGE_CSRF" \
        --data-urlencode "checkout_token=$EDGE_TOKEN" \
        --data-urlencode "items_json=$1" \
        --data-urlencode "customer_name=${3:-Http Edge Customer}" \
        --data-urlencode "contact_number=0917 555 1234" \
        --data-urlencode "delivery_address=${4:-88 Garden Street, Barangay Bloom, Quezon City}" \
        --data-urlencode "delivery_date=${5:-$GOOD_DATE}" \
        --data-urlencode "card_message=$msg" \
        --data-urlencode "payment_method=$pay" \
        --data-urlencode "gcash_ref=$gr" \
        "$BASE/checkout.php"
}
CODE=$(edge_post '[]' "$TMP/edge-empty.html" "" "" "" "" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-empty.html" "Your cart is empty" && pass "empty cart is rejected" || fail "empty cart is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":3,\"qty\":11}]" "$TMP/edge-overstock.html" "" "" "" "" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-overstock.html" "Only $EDGE_STOCK3 of Solstice Sunflower Box remain in stock" && pass "quantity over stock is rejected" || fail "quantity over stock is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":$INACTIVE_ID,\"qty\":1}]" "$TMP/edge-inactive.html" "" "" "" "" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-inactive.html" "Http Inactive Bloom is no longer available" && pass "inactive product in cart is rejected" || fail "inactive product in cart is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":3,\"qty\":1}]" "$TMP/edge-pastdate.html" "" "" "$(date -d '-1 day' '+%Y-%m-%d' 2>/dev/null || date -v-1d '+%Y-%m-%d')" "" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-pastdate.html" "The delivery date cannot be in the past" && pass "past delivery date is rejected" || fail "past delivery date is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":3,\"qty\":1}]" "$TMP/edge-fardate.html" "" "" "$FAR_DATE" "" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-fardate.html" "Delivery dates can be scheduled up to 180 days ahead" && pass "delivery date beyond 180 days is rejected" || fail "delivery date beyond 180 days is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":3,\"qty\":1}]" "$TMP/edge-payment.html" "" "" "" "" "BitPay")
[ "$CODE" = "200" ] && contains "$TMP/edge-payment.html" "Please choose a valid payment method" && pass "bogus payment method is rejected" || fail "bogus payment method is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":3,\"qty\":1}]" "$TMP/edge-gcash.html" "" "" "" "" "GCash" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-gcash.html" "Please enter a valid GCash reference number" && pass "GCash without reference is rejected" || fail "GCash without reference is rejected" "status $CODE"
CODE=$(edge_post "[{\"id\":3,\"qty\":1}]" "$TMP/edge-longmsg.html" "" "" "" "$LONG_MSG" "")
[ "$CODE" = "200" ] && contains "$TMP/edge-longmsg.html" "gift card message must be 300 characters or fewer" && pass "301-char card message is rejected" || fail "301-char card message is rejected" "status $CODE"
AFTER_EDGE=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
[ "$BEFORE_EDGE" = "$AFTER_EDGE" ] && pass "validation failures create no orders" || fail "validation failures create no orders" "before $BEFORE_EDGE after $AFTER_EDGE"

# XSS payloads must be stored raw and escaped on output
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-xss.html" "$BASE/checkout.php"
XSS_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-xss.html")
XSS_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-xss.html")
XSS=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/xss-response.html" -D "$TMP/xss-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$XSS_CSRF" \
    --data-urlencode "checkout_token=$XSS_TOKEN" \
    --data-urlencode "items_json=[{\"id\":3,\"qty\":1}]" \
    --data-urlencode "customer_name=Xss <script>alert(1)</script> Probe" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=1 <svg/onload=alert(3)> Garden Street, Quezon City" \
    --data-urlencode "delivery_date=$GOOD_DATE" \
    --data-urlencode "card_message=<img src=x onerror=alert(2)>" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php")
XSS_ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-HJ-NP-Z2-9]{12}/",$m[1],$c)) echo $c[0];' "$TMP/xss-headers.txt")
[ "$XSS" = "303" ] && [ -n "$XSS_ORDER_CODE" ] && pass "XSS payload order is accepted for storage" || fail "XSS payload order is accepted for storage" "status $XSS code $XSS_ORDER_CODE"
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/xss-confirm.html" "$BASE/checkout.php?order=$XSS_ORDER_CODE"
curl -sS -o "$TMP/xss-track.html" "$BASE/track.php?code=$XSS_ORDER_CODE"
grep -Fq '&lt;script&gt;alert(1)&lt;/script&gt;' "$TMP/xss-confirm.html" && pass "confirmation page escapes customer_name" || fail "confirmation page escapes customer_name"
grep -Fq '&lt;img src=x onerror=alert(2)&gt;' "$TMP/xss-confirm.html" && pass "confirmation page escapes card_message" || fail "confirmation page escapes card_message"
grep -Fq '&lt;svg/onload=alert(3)&gt;' "$TMP/xss-confirm.html" && \
    pass "confirmation page escapes delivery_address" || \
    { ! grep -Fq '<svg/onload=alert(3)>' "$TMP/xss-confirm.html" && \
      ! grep -Fq '<svg/onload=alert(3)>' "$TMP/xss-track.html" && \
      pass "delivery_address payload never rendered unescaped on confirmation or track" || \
      fail "delivery_address payload never rendered unescaped" "raw svg payload on a page"; }
grep -Fq '<script>alert(1)' "$TMP/xss-confirm.html" && fail "confirmation page has no unescaped script" "raw payload found" || pass "confirmation page has no unescaped script"
grep -Fq 'Xss &lt;.' "$TMP/xss-track.html" && pass "track.php escapes customer_name" || fail "track.php escapes customer_name"
if grep -Eq '<(script|img|svg)[^>]*alert' "$TMP/xss-track.html"; then
    fail "track.php has no unescaped script" "raw payload found"
else
    pass "track.php has no unescaped script"
fi

echo ""
echo "LIKE wildcard escaping"
echo "----------------------"
LIKE_CODE=$(curl -sS -o "$TMP/like-50.html" -w '%{http_code}' "$BASE/index.php?q=50%25")
if [ "$LIKE_CODE" = "200" ] && contains "$TMP/like-50.html" "Percent Probe Rose" && contains "$TMP/like-50.html" "1 arrangement" && ! grep -Fq "Elysian Blush Bouquet" "$TMP/like-50.html"; then
    pass "q=50%25 matches only the literal '%' row"
else
    fail "q=50%25 matches only the literal '%' row" "status $LIKE_CODE"
fi
curl -sS -o "$TMP/like-percent.html" "$BASE/index.php?q=%25"
if contains "$TMP/like-percent.html" "Percent Probe Rose" && contains "$TMP/like-percent.html" "1 arrangement" && ! grep -Fq "Elysian Blush Bouquet" "$TMP/like-percent.html"; then
    pass "q=%25 is treated as a literal percent sign"
else
    fail "q=%25 is treated as a literal percent sign" "wildcard matched all rows"
fi
curl -sS -o "$TMP/like-underscore.html" "$BASE/index.php?q=_"
if contains "$TMP/like-underscore.html" "Percent Probe Rose" && contains "$TMP/like-underscore.html" "1 arrangement" && ! grep -Fq "Elysian Blush Bouquet" "$TMP/like-underscore.html"; then
    pass "q=_ is treated as a literal underscore"
else
    fail "q=_ is treated as a literal underscore" "wildcard matched all rows"
fi
curl -sS -o "$TMP/like-esc.html" "$BASE/index.php?q=Zellie%5C"
if contains "$TMP/like-esc.html" "No arrangements found"; then
    pass "q=backslash does not break the query"
else
    fail "q=backslash does not break the query"
fi

echo ""
echo "Static responsiveness checks"
echo "----------------------------"
php -r '
$css = file_get_contents($argv[1] . "/public/assets/css/style.css");
$checks = [
    "css has product-detail section"   => str_contains($css, ".product-detail"),
    "css has qty-stepper styles"       => str_contains($css, ".qty-stepper"),
    "css has related-products container" => str_contains($css, ".related-products"),
    "css has cart-page section"        => str_contains($css, ".cart-page"),
    "css fluid product detail image"   => (bool) preg_match("/\.product-detail__figure img\s*\{[^}]*width:\s*100%/s", $css),
    "css product detail adapts above 900px" => (bool) preg_match("/@media \(min-width: 900px\)[\s\S]*?\.product-detail\s*\{[^}]*grid-template-columns/s", $css),
    "css cart adapts below 559px"      => (bool) preg_match("/@media \(max-width: 559px\)[\s\S]*?\.cart-page\s*\{/s", $css),
    "no huge fixed widths in css"      => !preg_match_all("/(?<!max-)(?<!min-)width:\s*(?:[5-9]\d{2}|\d{4,})px\s*;/", $css, $m) || count($m[0]) === 0,
];
Foreach ($checks as $name => $ok) { echo (($ok) ? "PASS $name\n" : "FAIL $name\n"); }
' "$ROOT" > "$TMP/css-checks.txt"
while IFS= read -r line; do
    case "$line" in
        PASS*) pass "${line#PASS }" ;;
        FAIL*) fail "${line#FAIL }" ;;
    esac
done < "$TMP/css-checks.txt"
grep -Eq 'style="[^"]*width' "$ROOT/public/product.php" "$ROOT/public/cart.php" 2>/dev/null \
    && fail "product.php and cart.php use no fixed-width inline styles" \
    || pass "product.php and cart.php use no fixed-width inline styles"

ASSET_CSS=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/assets/css/style.css?v=20261111-admin")
ASSET_JS=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/assets/js/app.js?v=20261111-admin")
[ "$ASSET_CSS" = "200" ] && pass "versioned stylesheet request returns 200" || fail "versioned stylesheet request returns 200" "got $ASSET_CSS"
[ "$ASSET_JS" = "200" ] && pass "versioned script request returns 200" || fail "versioned script request returns 200" "got $ASSET_JS"

# ---------------------------------------------------------------------------
# Appended security remediation coverage.
# Order matters: the checkout-session tests below must run BEFORE the rate
# limit block (this suite shares one IP — a lockout would poison them), so
# the rate limit section is the very last block in the file.
# ---------------------------------------------------------------------------
echo ""
echo "Confirmation session binding"
echo "----------------------------"
# (a) Owner path: the ordering session that placed the order still sees the
# confirmation, with the customer's own details. NOTE: GOOD_ORDER_CODE was
# placed after the admin logout (which destroys the shared session), so its
# binding is intact; the first ORDER_CODE predates it and is track-only.
OWN_CONFIRM_CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/own-confirm.html" -w '%{http_code}' "$BASE/checkout.php?order=$GOOD_ORDER_CODE")
if [ "$OWN_CONFIRM_CODE" = "200" ] && contains "$TMP/own-confirm.html" "$GOOD_ORDER_CODE" && contains "$TMP/own-confirm.html" "Order received" && contains "$TMP/own-confirm.html" "Http Patient Customer"; then
    pass "owner session still renders the full confirmation"
else
    fail "owner session still renders the full confirmation" "status $OWN_CONFIRM_CODE"
fi

# (b) Without the ordering session the order is never revealed: 303 redirect
# to the code-knowledge gated tracker and no customer data in the body.
NO_SESSION_STATUS=$(curl -sS -o "$TMP/no-session-body.html" -D "$TMP/no-session-headers.txt" -w '%{http_code}|%{redirect_url}' "$BASE/checkout.php?order=$GOOD_ORDER_CODE")
case "$NO_SESSION_STATUS" in
    303*track.php*) pass "confirmation without session redirects to track.php" ;;
    *) fail "confirmation without session redirects to track.php" "$NO_SESSION_STATUS" ;;
esac
! grep -Fq "Http Patient Customer" "$TMP/no-session-body.html" && pass "sessionless confirmation leaks no customer data" || fail "sessionless confirmation leaks no customer data" "customer name found in body"

# (c) Refresh behaviour: with the cookie jar the confirmation still renders.
REFRESH_CODE=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/refresh-confirm.html" -w '%{http_code}' "$BASE/checkout.php?order=$GOOD_ORDER_CODE")
[ "$REFRESH_CODE" = "200" ] && contains "$TMP/refresh-confirm.html" "$GOOD_ORDER_CODE" && pass "confirmation refresh with session still works" || fail "confirmation refresh with session still works" "status $REFRESH_CODE"

echo ""
echo "GCash masking and idempotent replay landing"
echo "-------------------------------------------"
# A second checkout paid via GCash; the owner view must show only the masked
# reference and a token replay must still land on the confirmation page.
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-gcash.html" "$BASE/checkout.php"
GC_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-gcash.html")
GC_LOADED=$(extract_hidden form_loaded_at < "$TMP/checkout-gcash.html")
GC_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-gcash.html")
sleep 3.5
GC_POST=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/gcash-response.html" -D "$TMP/gcash-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$GC_CSRF" \
    --data-urlencode "checkout_token=$GC_TOKEN" \
    --data-urlencode "form_loaded_at=$GC_LOADED" \
    --data-urlencode "website=" \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Gcash Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=GCash" \
    --data-urlencode "gcash_ref=GC12345678" \
    "$BASE/checkout.php")
GC_ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-HJ-NP-Z2-9]{12}/",$m[1],$c)) echo $c[0];' "$TMP/gcash-headers.txt")
[ "$GC_POST" = "303" ] && [ -n "$GC_ORDER_CODE" ] && pass "GCash order is created and redirects to confirmation" || fail "GCash order is created and redirects to confirmation" "status $GC_POST code $GC_ORDER_CODE"

GC_CONFIRM=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/gcash-confirm.html" -w '%{http_code}' "$BASE/checkout.php?order=$GC_ORDER_CODE")
if [ "$GC_CONFIRM" = "200" ] && contains "$TMP/gcash-confirm.html" 'GC••••••78'; then
    pass "owner confirmation shows the masked GCash reference"
else
    fail "owner confirmation shows the masked GCash reference" "status $GC_CONFIRM"
fi
! grep -Fq "GC12345678" "$TMP/gcash-confirm.html" && pass "owner confirmation never shows the full GCash reference" || fail "owner confirmation never shows the full GCash reference" "full ref found"

# Replay the same idempotency token: no second order, and the replayed
# redirect must land on the confirmation page for the owning session.
BEFORE_REPLAY=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
REPLAY=$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -D "$TMP/replay-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$GC_CSRF" \
    --data-urlencode "checkout_token=$GC_TOKEN" \
    --data-urlencode "items_json=[{\"id\":$PRODUCT_ID,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Gcash Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=GCash" \
    --data-urlencode "gcash_ref=GC12345678" \
    "$BASE/checkout.php")
AFTER_REPLAY=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
REPLAY_LOCATION=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m)) echo $m[1];' "$TMP/replay-headers.txt")
if [ "$REPLAY" = "303" ] && [ "$BEFORE_REPLAY" = "$AFTER_REPLAY" ]; then
    pass "replayed submission creates no second order"
else
    fail "replayed submission creates no second order" "status $REPLAY before $BEFORE_REPLAY after $AFTER_REPLAY"
fi
REPLAY_CODE=$(echo "$REPLAY_LOCATION" | grep -oE 'ZF-[A-HJ-NP-Z2-9]{12}' | head -1)
REPLAY_CONFIRM=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/replay-confirm.html" -w '%{http_code}' "$BASE/checkout.php?order=$REPLAY_CODE")
[ "$REPLAY_CONFIRM" = "200" ] && contains "$TMP/replay-confirm.html" "Order received" && pass "replayed redirect still lands on the confirmation page" || fail "replayed redirect still lands on the confirmation page" "status $REPLAY_CONFIRM location '$REPLAY_LOCATION'"

# ---------------------------------------------------------------------------
# Order rate limiting. The storefront session/IP (same cookie jar, same
# 127.0.0.1 source) has placed FOUR successful orders so far: the first
# checkout, the >2s bot-guard order, the XSS probe order and the GCash
# order. One more is still under the default cap of five (ZF_ORDER_RATE_
# LIMIT=5 is exported to the server above); the one after that must be
# rejected before any state changes. Replays of the GCash token never burn
# the quota: idempotent duplicates resolve before the cap is consulted.
# Kept just before the lookup throttle because both consume per-IP quota
# and that section must stay last.
# ---------------------------------------------------------------------------
echo ""
echo "Order rate limiting"
echo "-------------------"
RATE_LOG="$TMP/order-notifications.log"

# 5th order within the hour: valid tokens, patient submit — must succeed.
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-rate5.html" "$BASE/checkout.php"
RATE5_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-rate5.html")
RATE5_LOADED=$(extract_hidden form_loaded_at < "$TMP/checkout-rate5.html")
RATE5_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-rate5.html")
sleep 3.5
RATE5=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/rate5-response.html" -D "$TMP/rate5-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$RATE5_CSRF" \
    --data-urlencode "checkout_token=$RATE5_TOKEN" \
    --data-urlencode "form_loaded_at=$RATE5_LOADED" \
    --data-urlencode "website=" \
    --data-urlencode "items_json=[{\"id\":1,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Fifth Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php")
RATE5_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-HJ-NP-Z2-9]{12}/",$m[1],$c)) echo $c[0];' "$TMP/rate5-headers.txt")
if [ "$RATE5" = "303" ] && [ -n "$RATE5_CODE" ]; then
    pass "the 5th order within the hour still succeeds (under the cap)"
else
    fail "the 5th order within the hour still succeeds (under the cap)" "status $RATE5 code $RATE5_CODE"
fi

# 6th order within the hour: the cap is now full. The friendly error must
# render and no state may change: no order row, no stock decrement, no
# notification line.
# Baselines are captured here — AFTER the 5th order has succeeded and been
# recorded, BEFORE the rejected 6th is attempted — so the delta below spans
# only the rejected request.
ORDERS_BEFORE_RATE=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
STOCK1_BEFORE_RATE=$(sqlite3 "$TMP/shop.db" "SELECT stock_quantity FROM products WHERE id = 1;")
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-rate6.html" "$BASE/checkout.php"
RATE6_CSRF=$(extract_hidden csrf_token < "$TMP/checkout-rate6.html")
RATE6_LOADED=$(extract_hidden form_loaded_at < "$TMP/checkout-rate6.html")
RATE6_TOKEN=$(extract_hidden checkout_token < "$TMP/checkout-rate6.html")
sleep 3.5
RATE6=$(curl -sS -b "$JAR" -c "$JAR" -o "$TMP/rate6-response.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$RATE6_CSRF" \
    --data-urlencode "checkout_token=$RATE6_TOKEN" \
    --data-urlencode "form_loaded_at=$RATE6_LOADED" \
    --data-urlencode "website=" \
    --data-urlencode "items_json=[{\"id\":1,\"qty\":1}]" \
    --data-urlencode "customer_name=Http Sixth Customer" \
    --data-urlencode "contact_number=0917 555 1234" \
    --data-urlencode "delivery_address=88 Garden Street, Barangay Bloom, Quezon City" \
    --data-urlencode "delivery_date=$DELIVERY_DATE" \
    --data-urlencode "payment_method=COD" \
    "$BASE/checkout.php")
ORDERS_AFTER_RATE=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM orders;")
STOCK1_AFTER_RATE=$(sqlite3 "$TMP/shop.db" "SELECT stock_quantity FROM products WHERE id = 1;")
RATE_LOG_LINES=$(wc -l < "$RATE_LOG" 2>/dev/null || echo 0)
RATE_ATTEMPTS=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM order_attempts;" 2>/dev/null || echo 0)
if [ "$RATE6" = "200" ] && contains "$TMP/rate6-response.html" "Too many orders placed recently"; then
    pass "the 6th order within the hour is rejected with the friendly message"
else
    fail "the 6th order within the hour is rejected with the friendly message" "status $RATE6"
fi
if [ "$ORDERS_AFTER_RATE" = "$ORDERS_BEFORE_RATE" ] && [ "$STOCK1_AFTER_RATE" = "$STOCK1_BEFORE_RATE" ] \
    && [ "$RATE_LOG_LINES" = "5" ]; then
    pass "the rate-limited order leaves no partial state (no order row, stock untouched, no notification line)"
else
    fail "the rate-limited order leaves no partial state (no order row, stock untouched, no notification line)" \
        "orders $ORDERS_BEFORE_RATE→$ORDERS_AFTER_RATE stock $STOCK1_BEFORE_RATE→$STOCK1_AFTER_RATE notification lines $RATE_LOG_LINES"
fi
if [ "${RATE_ATTEMPTS:-0}" = "5" ]; then
    pass "order_attempts records successful orders only (5 rows)"
else
    fail "order_attempts records successful orders only (5 rows)" "rows: ${RATE_ATTEMPTS:-0}"
fi

echo ""
echo "Lookup rate limiting (must stay LAST — lockout is per-IP)"
echo "---------------------------------------------------------"
# (a) A legitimate successful lookup just before the block: no failure is
# recorded for resolved lookups, so this must show the real order.
PRE_CODE=$(curl -sS -o "$TMP/lock-valid.html" -w '%{http_code}' "$BASE/track.php?code=$ORDER_CODE")
if [ "$PRE_CODE" = "200" ] && contains "$TMP/lock-valid.html" "$ORDER_CODE"; then
    pass "valid lookup succeeds immediately before the block"
else
    fail "valid lookup succeeds immediately before the block" "status $PRE_CODE"
fi

# (b) Ten failed lookups cross the failure threshold (the earlier invalid
# 'ZF-NOPE00' lookup is the first recorded failure of this window).
for i in 1 2 3 4 5 6 7 8 9 10; do
    curl -sS -o /dev/null "$BASE/track.php?code=ZF-LOCK$i" || true
done
RECORDED=$(sqlite3 "$TMP/shop.db" "SELECT COUNT(*) FROM lookup_attempts;" 2>/dev/null)
[ "${RECORDED:-0}" -ge 10 ] && pass "failed lookups were recorded (${RECORDED} rows)" || fail "failed lookups were recorded" "rows: $RECORDED"

# (c) The 11th lookup — an invalid code — gets the generic not-found page.
LOCKED_INVALID_CODE=$(curl -sS -o "$TMP/locked-invalid.html" -w '%{http_code}' "$BASE/track.php?code=ZF-LOCKEDX")
[ "$LOCKED_INVALID_CODE" = "200" ] && contains "$TMP/locked-invalid.html" "No order found" && pass "blocked invalid lookup returns the generic not-found page" || fail "blocked invalid lookup returns the generic not-found page" "status $LOCKED_INVALID_CODE"

# (d) The 12th lookup — the VALID order code — must get the exact same
# generic page: no order details, no block wording, no customer name.
LOCKED_VALID_CODE=$(curl -sS -o "$TMP/locked-valid.html" -w '%{http_code}' "$BASE/track.php?code=$ORDER_CODE")
[ "$LOCKED_VALID_CODE" = "200" ] && contains "$TMP/locked-valid.html" "No order found" && pass "blocked valid lookup returns the generic not-found page" || fail "blocked valid lookup returns the generic not-found page" "status $LOCKED_VALID_CODE"
! grep -Fq "Http Test Customer" "$TMP/locked-valid.html" && pass "blocked valid lookup leaks no customer data" || fail "blocked valid lookup leaks no customer data" "customer name found while locked"
! grep -Eiq 'too many|rate limit|attempts' "$TMP/locked-valid.html" && pass "blocked valid lookup carries no block wording" || fail "blocked valid lookup carries no block wording"

# (e) Locked valid and locked invalid responses are byte-for-byte the same
# generic experience (same empty-state heading, no "too many attempts" text).
if contains "$TMP/locked-valid.html" "No order found" && contains "$TMP/locked-invalid.html" "No order found" \
   && ! grep -Eiq 'too many|rate limit|try again later|blocked' "$TMP/locked-valid.html" "$TMP/locked-invalid.html"; then
    pass "locked responses are indistinguishable from a normal miss"
else
    fail "locked responses are indistinguishable from a normal miss"
fi

# ---------------------------------------------------------------------------
# Admin session expiration & invalidation.
# Runs on its OWN second php -S instance (port ZF_TEST_PORT2, default 8124)
# with its own temp DB and short timeout env overrides, so sessions can be
# aged in seconds without touching the main server, its DB, or data/shop.db.
# Placed after the rate-limit block because it uses an entirely separate
# server; nothing above depends on it and nothing below follows it.
# ---------------------------------------------------------------------------
echo ""
echo "Admin session expiration and invalidation (isolated server on port ${ZF_TEST_PORT2:-8124})"
echo "-------------------------------------------------------------------------------------------"
PORT2="${ZF_TEST_PORT2:-8124}"
BASE2="http://127.0.0.1:${PORT2}"
JAR2="$TMP/jar2.txt"
JAR2_OLD="$TMP/jar2-old.txt"
ISO_LOG="$TMP/server2.log"

ZF_DB_PATH="$TMP/shop2.db" \
ZF_LOG_PATH="$TMP/error2.log" \
ZF_ADMIN_USER="admin" \
ZF_ADMIN_PASSWORD="HttpTest123!" \
ZF_ADMIN_IDLE_TIMEOUT=2 \
ZF_ADMIN_ABSOLUTE_TIMEOUT=3 \
ZF_DEBUG=0 \
php -S "127.0.0.1:${PORT2}" -t "$ROOT/public" "$ROOT/public/router.php" >"$ISO_LOG" 2>&1 &
ISO_PID=$!

for _ in $(seq 1 40); do
    if curl -sS -o /dev/null "$BASE2/" 2>/dev/null; then break; fi
    sleep 0.2
done

sid_of() {
    awk '$6 == "ZELLIEFLEURS" { print $7 }' "$1" 2>/dev/null | tail -n 1
}

# Login on the isolated server; capture the pre- and post-login session ids.
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login.html" "$BASE2/admin/login.php"
SID_BEFORE=$(sid_of "$JAR2")
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login.html")
[ -n "$ISO_CSRF" ] && pass "isolated login form includes a CSRF token" || fail "isolated login form includes a CSRF token"

ISO_LOGIN=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login-post.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=HttpTest123!" \
    "$BASE2/admin/login.php")
SID_AFTER=$(sid_of "$JAR2")
if [ "$ISO_LOGIN" = "303" ] && [ -n "$SID_BEFORE" ] && [ -n "$SID_AFTER" ] && [ "$SID_BEFORE" != "$SID_AFTER" ]; then
    pass "login regenerates the session id"
else
    fail "login regenerates the session id" "status $ISO_LOGIN before '$SID_BEFORE' after '$SID_AFTER'"
fi

DASH2=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-dash.html" -w '%{http_code}' "$BASE2/admin/index.php")
[ "$DASH2" = "200" ] && pass "immediate admin access works after login" || fail "immediate admin access works after login" "status $DASH2"

# Idle timeout: 2s of inactivity must bounce the admin session to login.
# The sleep is deliberately generous: on some Android devices CLOCK_REALTIME
# (PHP time()) is slewed during sleeps and advances slower than the shell's
# monotonic clock (observed: 2.0s of realtime across a 2.5s sleep). A sleep
# at the 2s idle boundary therefore races; 4s of real time guarantees the
# server-side elapsed time exceeds the 2s idle window under worst-case slew.
sleep 4
ISO_EXPIRED_STATUS=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE2/admin/index.php")
case "$ISO_EXPIRED_STATUS" in
    303*login.php*) pass "idle timeout redirects the admin session to login" ;;
    *) fail "idle timeout redirects the admin session to login" "$ISO_EXPIRED_STATUS" ;;
esac
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-expired.html" "$BASE2/admin/login.php?expired=1"
contains "$TMP/iso-expired.html" "Your session has expired" && contains "$TMP/iso-expired.html" "Please sign in again" \
    && pass "generic expiration message renders on the login page" \
    || fail "generic expiration message renders on the login page"

# The shared session must survive: only admin keys were cleared, so the
# storefront keeps serving the SAME session id (a session_destroy would
# mint a brand-new id under use_strict_mode on the next request).
STORE2=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-store.html" -w '%{http_code}' "$BASE2/")
SID_EXP=$(sid_of "$JAR2")
if [ "$STORE2" = "200" ] && [ -n "$SID_EXP" ] && [ "$SID_EXP" = "$SID_AFTER" ]; then
    pass "storefront keeps serving the shared session after admin expiry"
else
    fail "storefront keeps serving the shared session after admin expiry" "status $STORE2 sid '$SID_EXP' expected '$SID_AFTER'"
fi

# Activity refresh: re-login, then two admin requests 1.2s apart must both
# be authorized (2.4s since login would exceed the 2s idle limit if the
# refresh were broken). Then the absolute cap (3s since login) must expire
# the session even though activity was recent.
rm -f "$JAR2"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login2.html" "$BASE2/admin/login.php"
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login2.html")
ISO_LOGIN2=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=HttpTest123!" \
    "$BASE2/admin/login.php")
[ "$ISO_LOGIN2" = "303" ] && pass "re-login after expiry works" || fail "re-login after expiry works" "status $ISO_LOGIN2"

sleep 1.2
A1=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}' "$BASE2/admin/index.php")
sleep 1.2
A2=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}' "$BASE2/admin/index.php")
if [ "$A1" = "200" ] && [ "$A2" = "200" ]; then
    pass "admin activity refreshes the idle timer"
else
    fail "admin activity refreshes the idle timer" "first $A1 second $A2"
fi

# The absolute cap (3s since login) must expire the session even though
# activity was recent. Sleep generous for CLOCK_REALTIME slewing on some
# Android devices (see the idle-timeout note above): the A1/A2 gaps stay
# under the 2s idle window server-side, so any expiry here is attributable
# to the absolute cap, while 2.4s of real time guarantees the cumulative
# server-side login age exceeds 3s even at ~70% slew.
sleep 2.4
A3=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE2/admin/index.php")
case "$A3" in
    303*login.php*) pass "absolute timeout expires the session despite recent activity" ;;
    *) fail "absolute timeout expires the session despite recent activity" "$A3" ;;
esac

# Once expired, no protected page is reachable with the same jar.
O1=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE2/admin/orders.php")
O2=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE2/admin/products.php")
case "$O1" in
    303*login.php*) pass "expired session cannot access orders.php" ;;
    *) fail "expired session cannot access orders.php" "$O1" ;;
esac
case "$O2" in
    303*login.php*) pass "expired session cannot access products.php" ;;
    *) fail "expired session cannot access products.php" "$O2" ;;
esac

# Logout invalidation: keep a copy of the pre-logout jar and prove it is
# dead after the CSRF-protected logout POST.
rm -f "$JAR2"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login3.html" "$BASE2/admin/login.php"
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login3.html")
curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=HttpTest123!" \
    "$BASE2/admin/login.php"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-logout-pre.html" "$BASE2/admin/index.php"
LOGOUT_CSRF=$(extract_hidden csrf_token < "$TMP/iso-logout-pre.html")
cp "$JAR2" "$JAR2_OLD"
LOGOUT2=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$LOGOUT_CSRF" \
    "$BASE2/admin/logout.php")
OLD_JAR_STATUS=$(curl -sS -b "$JAR2_OLD" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE2/admin/index.php")
if [ "$LOGOUT2" = "303" ] && case "$OLD_JAR_STATUS" in 303*login.php*) true ;; *) false ;; esac; then
    pass "logout invalidates the old session cookie"
else
    fail "logout invalidates the old session cookie" "logout $LOGOUT2 old-jar $OLD_JAR_STATUS"
fi

# Standard error message for a wrong password on the isolated server too.
rm -f "$JAR2"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login4.html" "$BASE2/admin/login.php"
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login4.html")
WRONG2=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-wrong.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=still-wrong-password" \
    "$BASE2/admin/login.php")
[ "$WRONG2" = "200" ] && contains "$TMP/iso-wrong.html" "Invalid username or password" \
    && pass "isolated server rejects a wrong password with the standard error" \
    || fail "isolated server rejects a wrong password with the standard error" "status $WRONG2"

# Password-change invalidation — LAST on this server because it kills every
# session for the account. Uses the exact POST fields of settings.php.
rm -f "$JAR2"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login5.html" "$BASE2/admin/login.php"
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login5.html")
PW_LOGIN=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=HttpTest123!" \
    "$BASE2/admin/login.php")
cp "$JAR2" "$JAR2_OLD"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-settings.html" "$BASE2/admin/settings.php"
SET_CSRF=$(extract_hidden csrf_token < "$TMP/iso-settings.html")
PW_CHANGE=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-pwchange.html" -D "$TMP/iso-pwchange-headers.txt" -w '%{http_code}' \
    --data-urlencode "csrf_token=$SET_CSRF" \
    --data-urlencode "action=change_password" \
    --data-urlencode "current_password=HttpTest123!" \
    --data-urlencode "new_password=NewIsoPass456!" \
    --data-urlencode "confirm_password=NewIsoPass456!" \
    "$BASE2/admin/settings.php")
PW_LOCATION=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m)) echo $m[1];' "$TMP/iso-pwchange-headers.txt")
if [ "$PW_CHANGE" = "303" ] && [ "$PW_LOGIN" = "303" ] && grep -Fq "login.php?changed=1" <<<"$PW_LOCATION"; then
    pass "password change redirects to the sign-in page with a notice"
else
    fail "password change redirects to the sign-in page with a notice" "login $PW_LOGIN change $PW_CHANGE location '$PW_LOCATION'"
fi

OLD_JAR_PW=$(curl -sS -b "$JAR2_OLD" -o /dev/null -w '%{http_code}|%{redirect_url}' "$BASE2/admin/index.php")
case "$OLD_JAR_PW" in
    303*login.php*) pass "password change invalidates all existing admin sessions" ;;
    *) fail "password change invalidates all existing admin sessions" "$OLD_JAR_PW" ;;
esac

# The new password must work; the old one must be rejected.
rm -f "$JAR2"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login6.html" "$BASE2/admin/login.php"
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login6.html")
NEW_LOGIN=$(curl -sS -b "$JAR2" -c "$JAR2" -o /dev/null -w '%{http_code}' \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=NewIsoPass456!" \
    "$BASE2/admin/login.php")
NEW_DASH=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-dash3.html" -w '%{http_code}' "$BASE2/admin/index.php")
[ "$NEW_LOGIN" = "303" ] && [ "$NEW_DASH" = "200" ] \
    && pass "login with the new password succeeds" \
    || fail "login with the new password succeeds" "login $NEW_LOGIN dash $NEW_DASH"

rm -f "$JAR2"
curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-login7.html" "$BASE2/admin/login.php"
ISO_CSRF=$(extract_hidden csrf_token < "$TMP/iso-login7.html")
OLD_PW=$(curl -sS -b "$JAR2" -c "$JAR2" -o "$TMP/iso-oldpw.html" -w '%{http_code}' \
    --data-urlencode "csrf_token=$ISO_CSRF" \
    --data-urlencode "username=admin" \
    --data-urlencode "password=HttpTest123!" \
    "$BASE2/admin/login.php")
[ "$OLD_PW" = "200" ] && contains "$TMP/iso-oldpw.html" "Invalid username or password" \
    && pass "old password is rejected after the change" \
    || fail "old password is rejected after the change" "status $OLD_PW"

if grep -Eq 'PHP (Warning|Fatal error|Parse error)' "$ISO_LOG"; then
    fail "isolated server log contains no PHP warnings or fatal errors"
    grep -E 'PHP (Warning|Fatal error|Parse error)' "$ISO_LOG" | head -5
else
    pass "isolated server log contains no PHP warnings or fatal errors"
fi

echo ""
echo "Error handling: branded 404 and protected log (main server)"
echo "-----------------------------------------------------------"
MISSING_ROUTE=$(curl -sS -o "$TMP/404-route.html" -w '%{http_code}' "$BASE/definitely-not-here.php")
if [ "$MISSING_ROUTE" = "404" ]; then pass "missing route returns 404"; else fail "missing route returns 404" "got $MISSING_ROUTE"; fi
contains "$TMP/404-route.html" "could not find that page" && pass "missing route shows the branded 404 message" || fail "missing route shows the branded 404 message"
contains "$TMP/404-route.html" "logo-mark.svg" && pass "branded 404 keeps the visual identity" || fail "branded 404 keeps the visual identity"
! grep -Eq 'Fatal error|Warning:|Stack trace|ZF_ROOT|shop\.db|/data/data' "$TMP/404-route.html" && pass "branded 404 leaks no internal details" || fail "branded 404 leaks no internal details"

MISSING_ASSET=$(curl -sS -o "$TMP/404-asset.html" -w '%{http_code}' "$BASE/assets/js/definitely-not-here.js")
[ "$MISSING_ASSET" = "404" ] && contains "$TMP/404-asset.html" "could not find that page" && pass "missing asset returns the branded 404" || fail "missing asset returns the branded 404" "got $MISSING_ASSET"

API404=$(curl -sS -o "$TMP/404-api.json" -w '%{http_code}' "$BASE/api/definitely-not-here.php")
if [ "$API404" = "404" ] && php -r '$d=json_decode(file_get_contents($argv[1]),true); exit(($d!==null && isset($d["ok"]) && $d["ok"]===false) ? 0 : 1);' "$TMP/404-api.json"; then
    pass "missing API route returns a JSON 404"
else
    fail "missing API route returns a JSON 404" "got $API404"
fi

MISSING_PRODUCT=$(curl -sS -o "$TMP/404-product.html" -w '%{http_code}' "$BASE/product.php?id=999999")
[ "$MISSING_PRODUCT" = "404" ] && contains "$TMP/404-product.html" "could not be found" && pass "missing product still returns a branded 404" || fail "missing product still returns a branded 404" "got $MISSING_PRODUCT"

# Main-server protected log: written outside the webroot, never into the
# repo's data/ directory. With ZF_DEBUG=0 and no errors nothing is logged
# (expected validation failures and 404s are deliberately not logged), so
# an absent file is a clean result; if entries exist they must be clean.
if [ -f "$TMP/error.log" ]; then
    if grep -Eq 'PHP (Warning|Fatal error|Parse error)' "$TMP/error.log"; then
        fail "main server protected log is clean"
        grep -E 'PHP (Warning|Fatal error|Parse error)' "$TMP/error.log" | head -5
    else
        pass "main server protected log is clean"
    fi
    LOG_PERMS=$(stat -c '%a' "$TMP/error.log" 2>/dev/null || stat -f '%Lp' "$TMP/error.log" 2>/dev/null)
    [ "$LOG_PERMS" = "600" ] && pass "main server protected log has 0600 permissions" || fail "main server protected log has 0600 permissions" "got $LOG_PERMS"
else
    pass "main server logged nothing (no errors occurred) — protected log target verified on the broken-DB server below"
fi

echo ""
echo "Error handling: clean 500 and API JSON (isolated broken-DB server on port ${ZF_TEST_PORT3:-8125})"
echo "------------------------------------------------------------------------------------------------"
PORT3="${ZF_TEST_PORT3:-8125}"
BASE3="http://127.0.0.1:${PORT3}"
BROKEN_LOG="$TMP/broken-error.log"
mkdir -p "$TMP/ro"
chmod 555 "$TMP/ro"

BROKEN_PID=""
# Cleanup of the broken-DB server is handled by the shared cleanup() trap at
# the top of the file (BROKEN_PID is included there); the read-only directory
# needs its write bit back before rm -rf can clear $TMP.

ZF_DB_PATH="$TMP/ro/shop.db" \
ZF_LOG_PATH="$BROKEN_LOG" \
ZF_DEBUG=0 \
php -S "127.0.0.1:${PORT3}" -t "$ROOT/public" "$ROOT/public/router.php" >"$TMP/broken-server.log" 2>&1 &
BROKEN_PID=$!

for _ in $(seq 1 40); do
    if curl -sS -o /dev/null "$BASE3/" 2>/dev/null; then break; fi
    sleep 0.2
done

PAGE500=$(curl -sS -o "$TMP/500-page.html" -w '%{http_code}' "$BASE3/index.php")
if [ "$PAGE500" = "500" ]; then pass "uncaught database failure returns HTTP 500"; else fail "uncaught database failure returns HTTP 500" "got $PAGE500"; fi
contains "$TMP/500-page.html" "Something went wrong" && pass "clean 500 response is user-friendly" || fail "clean 500 response is user-friendly"
! grep -Eq 'PDOException|shop\.db|/data/data|Stack trace|ZF_|Exception' "$TMP/500-page.html" && pass "clean 500 exposes no internal details" || fail "clean 500 exposes no internal details"

API500=$(curl -sS -o "$TMP/500-api.json" -D "$TMP/500-api-headers.txt" -w '%{http_code}' "$BASE3/api/products.php")
if [ "$API500" = "500" ]; then pass "API failure returns HTTP 500"; else fail "API failure returns HTTP 500" "got $API500"; fi
if php -r '$d=json_decode(file_get_contents($argv[1]),true); exit(($d!==null && isset($d["ok"]) && $d["ok"]===false) ? 0 : 1);' "$TMP/500-api.json"; then
    pass "API failure returns valid JSON with ok=false"
else
    fail "API failure returns valid JSON with ok=false"
fi
grep -Eqi '^Content-Type: application/json' "$TMP/500-api-headers.txt" \
    && pass "API failure Content-Type is application/json" \
    || fail "API failure Content-Type is application/json"
! grep -Eq 'PDOException|Stack trace|<html|<body' "$TMP/500-api.json" && pass "API failure JSON exposes no internals and is never HTML" || fail "API failure JSON exposes no internals and is never HTML"

# The protected log recorded the failure with timestamp, type and source.
if grep -Eq '\[20[0-9]{2}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}' "$BROKEN_LOG" \
   && grep -Fq 'Database bootstrap failed' "$BROKEN_LOG"; then
    pass "protected log records the failure with a timestamp"
else
    fail "protected log records the failure with a timestamp"
fi
! grep -Eq 'password=|csrf_token=|session_id=' "$BROKEN_LOG" && pass "protected log contains no secret-shaped values" || fail "protected log contains no secret-shaped values"
BROKEN_PERMS=$(stat -c '%a' "$BROKEN_LOG" 2>/dev/null || stat -f '%Lp' "$BROKEN_LOG" 2>/dev/null)
[ "$BROKEN_PERMS" = "600" ] && pass "protected log file has 0600 permissions" || fail "protected log file has 0600 permissions" "got $BROKEN_PERMS"

echo ""
echo "${PASSED} passed, ${FAILED} failed."
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
