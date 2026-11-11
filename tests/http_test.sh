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
contains "$TMP/index.html" 'style.css?v=20261110-refresh' && pass "versioned stylesheet is served" || fail "versioned stylesheet is served"

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

echo ""
echo "Checkout bot-guards and edge-case validation"
echo "--------------------------------------------"
# Honeypot field must be visually hidden via its inline style
curl -sS -b "$JAR" -c "$JAR" -o "$TMP/checkout-hidden.html" "$BASE/checkout.php"
contains "$TMP/checkout-hidden.html" 'left:-9999px' && pass "honeypot field is visually hidden with inline style" || fail "honeypot field is visually hidden with inline style"

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
GOOD_ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-Z0-9]{6}/",$m[1],$c)) echo $c[0];' "$TMP/good-headers.txt")
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
XSS_ORDER_CODE=$(php -r '$h=file_get_contents($argv[1]); if(preg_match("/^Location:\\s*(.+)$/mi",$h,$m) && preg_match("/ZF-[A-Z0-9]{6}/",$m[1],$c)) echo $c[0];' "$TMP/xss-headers.txt")
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
grep -Fq '&lt;script&gt;alert(1)' "$TMP/xss-track.html" && pass "track.php escapes customer_name" || fail "track.php escapes customer_name"
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

ASSET_CSS=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/assets/css/style.css?v=20261110-refresh")
ASSET_JS=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/assets/js/app.js?v=20261110-refresh")
[ "$ASSET_CSS" = "200" ] && pass "versioned stylesheet request returns 200" || fail "versioned stylesheet request returns 200" "got $ASSET_CSS"
[ "$ASSET_JS" = "200" ] && pass "versioned script request returns 200" || fail "versioned script request returns 200" "got $ASSET_JS"

echo ""
echo "${PASSED} passed, ${FAILED} failed."
exit $([ "$FAILED" -eq 0 ] && echo 0 || echo 1)
