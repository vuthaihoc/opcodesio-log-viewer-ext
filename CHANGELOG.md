# Changelog

## v1.4.4 — 2026-10-13

- PHP 8.5: bỏ `ReflectionProperty::setAccessible()` trong `DebugbarLivewireSource` (deprecate từ 8.5, vô tác dụng từ 8.1). Mỗi query ở local có Debugbar từng sinh một dòng deprecation. Có thêm test cho bản vá Debugbar (trước đây chưa có test nào chạy qua đoạn này).
- Test: deprecation phát ra từ `src/` làm test đỏ. `phpunit.xml` có `failOnDeprecation`; Feature test có lớp chặn riêng ở `TestCase`, vì Laravel đưa deprecation vào kênh log nên PHPUnit không thấy.
- CI GitHub Actions: PHP 8.3 / 8.5 × Laravel 11 / 12 / 13, trừ cặp Laravel 11 × 8.5.

## v1.4.3 — 2026-10-11

- Tài liệu: mục "Khuyến nghị về token" trong `docs/agent.md` (mỗi project một token, máy chạy agent không giữ shared secret, ai sinh / phát token, xoay vòng); `secret --agent --show` trong hướng dẫn cài; skill dặn agent không tự sinh hay in token.

## v1.4.2 — 2026-10-11

Gộp phần còn hơn của bản slow log rút gọn trong gth (`app/Core/SqlLog`) trước khi gth chuyển sang package:

- Bảng của Laravel Passport (`oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_auth_codes`, `oauth_clients`) vào danh sách bảng nhạy cảm — không bao giờ in giá trị binding (`raw_bindings`, chỉ local).
- `itsgoingd/clockwork` vào `trace.skip_vendors` mặc định (Clockwork cũng nghe QueryExecuted, frame của nó chỉ là nhiễu).
- Test nhãn ngữ cảnh `[CLI][tên job / lệnh]` nối dây thật qua event của queue worker / artisan (kể cả lần thử lỗi còn retry, job trong `queue:work`).

## v1.4.1 — 2026-10-11

Không đổi code chạy — chỉ test:

- Test phần cần Laravel chuyển từ app vào package, chạy trên orchestra/testbench (`tests/Feature`): endpoint agent, lệnh, vá lộ secret, tải file host xa, SqlLogger, RequestLogger, gộp config, LoggableUrl. `composer test` chạy cả Unit + Feature (~125 test, vài giây, không cần DB / mạng).
- `require-dev` thêm `orchestra/testbench`, `livewire/livewire` (test resolver Livewire 4). `.gitattributes` loại tests khỏi bản cài.

## v1.4.0 — 2026-10-11

- **Request chậm / response lớn theo prefix** (`SlowLog\RequestLogger`, nhóm `slow_log.requests`): hai luật độc lập — `SLOW_LOG_REQUEST_TIME_PREFIXES` + `SLOW_LOG_REQUEST_TIME` (mặc định 2000 ms), `SLOW_LOG_RESPONSE_SIZE_PREFIXES` + `SLOW_LOG_RESPONSE_SIZE` (mặc định 512 KB); prefix rỗng = tắt. Prefix theo đoạn path, `*` như Str::is, `prefix:ngưỡng` riêng, `prefix:0` loại trừ. Một dòng `slow_log: request` kèm số query + ms DB của chính request.
- Bộ đọc của agent hiểu dòng `request`: bảng `pages` thêm `requests`, `slow_requests`, `big_responses`, `request_ms`, `request_db_ms`, `max_request_ms`, `max_bytes`; `messages` có khoá `slow request: …`.
- `SlowLog\WebContext`: nhãn + context request dùng chung cho mọi dòng slow log.

## v1.3.0 — 2026-10-09

- **Một file config `log-viewer-ext`** (`config/log-viewer-ext.php`, publish tag `log-viewer-ext-config`) thay cho `log-viewer-remote.php` + `slow-log.php`: auth / host xa ở gốc, nhóm `agent`, nhóm `slow_log`. Env giữ nguyên tên.
- Gộp **đệ quy** với config của app (thay cho gộp sâu một cấp): app chỉ ghi key mình đổi, kể cả trong nhóm lồng nhau; danh sách thì thay hẳn.
- Tương thích ngược: app còn hai file cũ thì vẫn được đọc, xếp dưới file mới; `log-viewer-remote:check` nhắc chuyển. Tag publish cũ (`log-viewer-remote-config`, `slow-log-config`) giờ publish file mới.
- Mặc định mới: `SLOW_LOG_CHANNEL=slow-log` (channel riêng), `LOG_VIEWER_AGENT_CHANNELS=slow-log`, `SLOW_LOG_TRACE_LIVEWIRE=false`, `SLOW_LOG_DEBUGBAR_LIVEWIRE=false` (hai phần dựa vào nội bộ Livewire 4 / Debugbar — app Livewire bật lên).

## v1.2.0 — 2026-10-09

**Agent phân tích log từ xa** — tính ngay trên host có file, không tải file về. Xem `docs/agent.md`.

- Endpoint `{route_path}/api/agent/{ping,files,aggregate,entries}`, Bearer = `LOG_VIEWER_AGENT_TOKEN` (chỉ đọc: chỉ hợp lệ ở nhóm này, không tải / xoá file, không vào UI) hoặc shared secret.
- Giới hạn theo channel cho **agent token** (`LOG_VIEWER_AGENT_CHANNELS`, mặc định chỉ channel slow log): channel → file (single / daily / stack / monolog stream). Shared secret đọc được mọi file Log Viewer liệt kê, như UI.
- Forward `?host=<id>` từ host đang xem (lệnh: `--via`): credential theo loại người gọi — agent token forward bằng agent token, không nâng quyền qua bước forward.
- `aggregate`: 6 bảng trong một lượt đọc — `levels`, `messages` (mọi entry); `pages` (gộp theo route / tên operation GraphQL / referer Livewire), `sql_waste` (Σ(xN−1)), `commands`, `slow_queries` (n + max, không avg) chỉ từ dòng slow log (`applies_to`). 342 MB / 77 nghìn entry: 3,9 s, 52 MB.
- Lọc + gom theo regex của người gọi: `match` / `contains` / `level` lọc trước mọi bảng; `group` thêm bảng gom (nhóm `key` = khoá, nhóm `sum` = số cộng dồn); `in=text` so trên cả entry.
- Trần `max_seconds` mỗi request + cursor giữ trạng thái ở server (quét chia nhỏ ra đúng kết quả quét một lượt); giới hạn lượt quét đồng thời (429 + Retry-After); cache kết quả file đã đóng.
- `from` / `to` / `date` theo giờ người hỏi (`log-viewer.timezone`), đổi sang giờ của log; tự chọn file daily trong khoảng.
- `entries`: đọc trọn entry theo `file@offset` hoặc lọc contains / regex / level; che email và token.
- Dùng lại nhận diện loại log của Log Viewer (`LogFile::type()`): `files` trả `type`; agent chỉ đọc file `laravel`, chọn đích danh file loại khác → 422.
- `ui_url` cho mỗi `sample` và mỗi entry: mở file trong UI Log Viewer với ô tìm kiếm điền sẵn đúng giây của entry (identifier của vendor; CLI dùng tên file); forward viết lại về UI của host đang xem kèm `host=`.
- Lệnh `log-viewer-remote:aggregate`, `:entries`, `:files` (in-process cho máy này hoặc HTTP tới host xa, tự đi theo cursor); `:check` thêm cột Agent; `:secret --agent`.
- Skill cho Claude Code: `vendor:publish --tag=log-viewer-remote-skill`.
- Config: nhóm `timeout`, `agent`, `trace`, `dedicated` được gộp sâu thêm một cấp — app chỉ ghi key mình đổi mà không mất key đọc env của package.
- Unit test PHP thuần ngay trong package (`composer test`).

## v1.1.0 — 2026-10-09

- **Slow query log** (`HocVT\LogViewerRemote\SlowLog`, config `slow-log`). Log ngay query chậm hơn ngưỡng; tổng kết mỗi request / job / command khi quá nhiều query, tổng thời gian quá lớn hoặc một query lặp nhiều lần (nghi N+1). **Mặc định tắt** — bật bằng `SLOW_LOG_ENABLED=true`. Tài liệu: `docs/slow-log.md`.
- Giá trị binding mặc định không ghi; `SLOW_LOG_RAW_BINDINGS` chỉ có tác dụng ở `local` và vẫn che hash, token, chuỗi dài, binary, bảng nhạy cảm.
- Truy vết query chậm quy file compiled về nguồn, bật tắt riêng: Blade (`trace.blade`), Livewire 4 (`trace.livewire`). Bản vá tên nguồn query của Debugbar cho Livewire: `debugbar_livewire` (chỉ local).
- `HocVT\LogViewerRemote\Support\LoggableUrl`: URL an toàn để ghi log — che tham số route / query có tên nhạy cảm và đoạn path trông như token.
- **Channel riêng cho slow log**: `SLOW_LOG_CHANNEL=slow-log` → `storage/logs/slow-log-YYYY-MM-DD.log`; package tự khai channel nếu app chưa có (nhóm `dedicated`: tên, đường dẫn, số ngày, quyền file).
- Context cho máy đọc trên mọi dòng slow log: `slow_log` (`query` | `summary`), `reasons`, `worst_duplicate`, `ms`, `connection`, `cli.context`, `req.route`. Dòng query chậm ở CLI mang tên job / lệnh (`[CLI][App\Jobs\X]`) thay vì `[CLI]` trơn.
- Dòng nhóm query luôn tách `xN` khỏi số ms (`%5s %7sms`); định dạng cũ dính thành `x10000100000ms` khi số lớn.
- **Sửa lỗi bảo mật:** danh sách host (`/api/hosts`, `window.LogViewer` ở trang chính) không còn mang `auth` / `headers` — trước đây ai xem được Log Viewer cũng đọc được shared secret trong mã nguồn trang. Credential chỉ đọc qua `Support\HostCredentials`; forward API tự làm qua `Support\RemoteHttp` (timeout `timeout.forward`, chép `Retry-After`).
- Yêu cầu PHP 8.2+ (Laravel 11 vốn đã cần), thêm `illuminate/database`.

## v1.0.0 — 2026-09-10

Bản phát hành đầu tiên. Mở rộng `opcodesio/log-viewer` cho mô hình một Log Viewer xem log của nhiều host.

- **Auth thống nhất.** Request server-to-server giữa các host đi bằng `Authorization: Bearer <shared secret>`. Người dùng thật thì hỏi Gate `viewLogViewer`.
- **Tải được file log của host ở xa.** Package gốc không làm được: nút Download gọi XHR sang origin khác nên CORS chặn, và route download bên đó vẫn nằm trong nhóm `api_middleware` nên trình duyệt không có session sẽ ăn 403. Package viết lại `download_url` về origin hiện tại rồi tải hộ server-to-server, stream về client theo chunk.
- **Khai host bằng `LOG_VIEWER_HOSTS`.** Chỉ URL trần mới được bù `route_path`; URL đã có path giữ nguyên nên hai host đặt prefix khác nhau vẫn chạy.
- **Mặc định `api_stateful_domains` theo `APP_URL`**, tránh lỗi UI 403 toàn bộ API.
- **`LogViewerRemote::authorizeUsing()`** cho app không dùng Gate được — điển hình là app cài `spatie/laravel-permission` nhưng model `User` không implement `Authorizable`.
- **Lệnh `log-viewer-remote:check`** kiểm tra kết nối tới từng host, và **`log-viewer-remote:secret`** sinh shared secret ghi vào `.env`.

Hỗ trợ Laravel 11/12/13, PHP 8.0+.
