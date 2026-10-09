---
name: log-viewer-remote
description: >
  Phân tích log Laravel trên host production / host xa mà KHÔNG tải file về — dùng lệnh
  `php artisan log-viewer-remote:aggregate|entries|files|check` (package hocvt/log-viewer-remote).
  Kích hoạt khi người dùng hỏi về lỗi, slow query, N+1, query thừa, trang / job tốn DB, hoặc
  muốn đọc log của một host (m1, worker, production…), hay khi định tải / grep file log từ server.
---

# Phân tích log từ xa

Tính ngay trên host có file, chỉ nhận về bảng gọn. **Đừng tải file log về rồi grep**: file
60–340 MB/ngày, còn một lượt `aggregate` trên host chỉ vài giây.

## Bắt đầu

```bash
php artisan log-viewer-remote:check                     # host nào đọc được, phiên bản, channel cho phép
php artisan log-viewer-remote:files --host=<host>       # file + channel + ngày
```

- `--host` = identifier trong `config/log-viewer.php` / `LOG_VIEWER_HOSTS`; bỏ trống = máy này.
- Không gọi thẳng được host đích thì `--via=<host đang xem> --host=<đích>` (host trung gian forward).
- Cột Agent `chưa có (host < 1.2)` = host chưa nâng cấp package → không dùng được, báo người dùng.
- Lệnh cảnh báo đang gửi shared secret = máy này thiếu `LOG_VIEWER_AGENT_TOKEN`; vẫn chạy được.

## Gom số liệu

```bash
php artisan log-viewer-remote:aggregate --host=<host> --channel=<channel> --date=YYYY-MM-DD [--only=…] [--top=20] [--json]
```

- `--channel`: mặc định channel slow log. Log lỗi chung thường là `daily` (web) / `daily_cli` (CLI) — xem lệnh `files`.
- Thời gian: `--date` = cả ngày theo giờ người hỏi; `--from` / `--to` nhận `"2026-10-05 07:00"`
  (giờ người hỏi), ISO có offset, hoặc `"-2 hours"`. Log ghi giờ theo `app.timezone` (thường UTC):
  đối chiếu `window.from/to` trong kết quả, đừng tự cộng trừ múi giờ.
- `--only` = `levels,messages,pages,sql_waste,commands,slow_queries` (+ `group`).
- **`levels`, `messages`, `group` đọc mọi entry; `pages`, `sql_waste`, `commands`, `slow_queries`
  CHỈ đọc dòng slow log** — chạy trên log lỗi (`daily`, `daily_cli`) thì trống, đó không phải lỗi.

| Câu hỏi | Bảng |
|---|---|
| Lỗi gì, bao nhiêu | `levels`, `messages` (channel log lỗi) |
| Trang nào chậm / nhiều query | `pages` |
| N+1, query thừa | `sql_waste` (`waste` = Σ(xN−1), cột `pages` = nơi sinh ra) |
| Job / lệnh nào tốn DB | `commands` |
| Query chậm nào hay gặp | `slow_queries` |

Câu hỏi riêng → tự gửi regex:

```bash
# lọc trước mọi bảng
… --level=error --match='OOM|timed out' --only=levels,messages
# gom theo regex: nhóm tên key = khoá, nhóm tên sum = số cộng dồn; --in=text so cả entry (chậm hơn)
… --in=text --group='App\\Jobs\\(?<key>\w+)' --only=group
```

Đưa người dùng link xem tận mắt: `ui_url` (thêm `--links` cho `aggregate`, `entries` luôn in `UI:`).
Bộ đọc chỉ hiểu log Laravel (cột `type` của lệnh `files`); file nginx / php-fpm… trả 422 — xem bằng UI.

Đọc trọn một entry: lấy cột `sample` (`file@offset`):

```bash
php artisan log-viewer-remote:entries --host=<host> --at=<file@offset>
php artisan log-viewer-remote:entries --host=<host> --channel=daily --date=… --contains="OOM" --limit=5
```

## Đọc số — đừng sai ở đây

- Query chậm: **đếm `n`, không lấy trung bình** — log chỉ ghi query vượt ngưỡng (đuôi phân phối
  đã bị cắt). `n` tăng mà `max_ms` đứng yên = bão hoà do khối lượng (N+1, thiếu cache), không
  phải query plan tồi. Muốn biết query nhanh hay chậm thật thì EXPLAIN / đo trực tiếp.
- `sql_waste` chỉ đếm query nằm trong top dòng của mỗi tổng kết — là cận dưới, không phải tổng.
- Xếp theo **nhóm trang** (`pages`), không theo URL lẻ. `livewire ← /x` = request Livewire của
  trang `/x`. `graphql.name=Op` = operation GraphQL (host API).
- `CLI ?` = log cũ chưa ghi tên job. `bỏ N khoá nhỏ` / `…` = phần đuôi đã gộp, top vẫn đúng.
- Kết quả là của MỘT host: hỏi về "production" thì chạy từng host (web, m1, m2…) rồi gộp.

## Luật

- Không in, không chép token / `.env` vào câu trả lời hay file.
- 403 kèm `allowed_channels` = channel đó không mở cho agent token; đừng đổi sang shared secret để vượt.
- Host trả 429 = đang bận: lệnh tự chờ; đừng chạy song song nhiều lệnh vào cùng host.
- Chi tiết, HTTP API, cấu hình: `vendor/hocvt/log-viewer-remote/docs/agent.md`.
