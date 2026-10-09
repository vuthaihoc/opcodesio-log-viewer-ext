# Changelog

## v1.2.0 — chưa phát hành

**Agent phân tích log từ xa** — tính ngay trên host có file, không tải file về. Xem `docs/agent.md`.

- Endpoint `{route_path}/api/agent/{ping,files,aggregate,entries}`, Bearer = `LOG_VIEWER_AGENT_TOKEN` (chỉ đọc: chỉ hợp lệ ở nhóm này, không tải / xoá file, không vào UI) hoặc shared secret.
- Giới hạn theo channel (`LOG_VIEWER_AGENT_CHANNELS`, mặc định chỉ channel slow log): channel → file (single / daily / stack / monolog stream); áp cho cả agent token lẫn shared secret.
- `aggregate`: 6 bảng trong một lượt đọc — `levels`, `messages`, `pages` (gộp theo route / tên operation GraphQL / referer Livewire), `sql_waste` (Σ(xN−1)), `commands`, `slow_queries` (n + max, không avg). 342 MB / 77 nghìn entry: 3,9 s, 52 MB.
- Trần `max_seconds` mỗi request + cursor giữ trạng thái ở server (quét chia nhỏ ra đúng kết quả quét một lượt); giới hạn lượt quét đồng thời (429 + Retry-After); cache kết quả file đã đóng.
- `from` / `to` / `date` theo giờ người hỏi (`log-viewer.timezone`), đổi sang giờ của log; tự chọn file daily trong khoảng.
- `entries`: đọc trọn entry theo `file@offset` hoặc lọc contains / regex / level; che email và token.
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
