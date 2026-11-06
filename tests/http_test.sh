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

cleanup() {
    if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
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
ZF_ADMIN_USER="admin" \
ZF_ADMIN_PASSWORD="HttpTest123!" \
ZF_DEBUG=0 \
php -S "127.0.0.1:${PORT}" -t "$ROOT/public" >"$SERVER_LOG" 2>&1 &
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
contains "$TMP/index.html" 'style.css?v=20261106-mobile' && pass "versioned stylesheet is served" || fail "versioned stylesheet is served"

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
ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-Z0-9]{6}/",$m[1],$c)) echo $c[0];' "$TMP/checkout-headers.txt")
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

if grep -Eq 'PHP (Warning|Fatal error|Parse error)' "$SERVER_LOG"; then
    fail "server log contains no PHP warnings or fatal errors"
    grep -E 'PHP (Warning|Fatal error|Parse error)' "$SERVER_LOG" | head -5
else
    pass "server log contains no PHP warnings or fatal errors"
fi

echo ""
echo "${PASSED} passed, ${FAILED} failed."
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
