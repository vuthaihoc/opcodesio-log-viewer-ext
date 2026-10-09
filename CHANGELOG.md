# Changelog

## v1.1.0 — 2026-10-09

- **Slow query log** (`HocVT\LogViewerRemote\SlowLog`, config `slow-log`). Log ngay query chậm hơn ngưỡng; tổng kết mỗi request / job / command khi quá nhiều query, tổng thời gian quá lớn hoặc một query lặp nhiều lần (nghi N+1). **Mặc định tắt** — bật bằng `SLOW_LOG_ENABLED=true`. Tài liệu: `docs/slow-log.md`.
- Giá trị binding mặc định không ghi; `SLOW_LOG_RAW_BINDINGS` chỉ có tác dụng ở `local` và vẫn che hash, token, chuỗi dài, binary, bảng nhạy cảm.
- Truy vết query chậm quy file compiled về nguồn, bật tắt riêng: Blade (`trace.blade`), Livewire 4 (`trace.livewire`). Bản vá tên nguồn query của Debugbar cho Livewire: `debugbar_livewire` (chỉ local).
- `HocVT\LogViewerRemote\Support\LoggableUrl`: URL an toàn để ghi log — che tham số route / query có tên nhạy cảm và đoạn path trông như token.
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
