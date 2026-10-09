# Agent phân tích log từ xa

Agent (Claude Code, script…) chạy trên máy dev hỏi số liệu log của host production mà **không
tải file về**. Việc đọc file diễn ra ngay trên host có file, agent chỉ nhận JSON hoặc bảng
gọn.

Trước đây agent phải tải file 60–340 MB/ngày rồi grep. Giờ một lượt đọc trên host làm cả 7
thao tác: 342 MB / 77 nghìn entry mất 3,9 s, RAM 52 MB.

```
máy dev                                   host production (m1, m2, worker…)
php artisan log-viewer-remote:aggregate   GET {log-viewer}/api/agent/aggregate
  --host=m1 --channel=slow-log      ───▶    Bearer LOG_VIEWER_AGENT_TOKEN
                                            đọc storage/logs/slow-log-*.log tại chỗ
  bảng markdown / JSON              ◀───    JSON gọn (top-N mỗi bảng)
```

## Cài đặt

**Trên mọi host** (cả host bị đọc lẫn máy dev), dùng `hocvt/log-viewer-remote` ≥ 1.2:

1. Sinh token **một lần** rồi copy cùng giá trị sang `.env` của mọi host:

   ```bash
   php artisan log-viewer-remote:secret --agent
   ```

2. Trên host bị đọc, khai các channel được phép đọc. Bỏ trống thì chỉ có channel của slow log:

   ```
   LOG_VIEWER_AGENT_CHANNELS=slow-log,daily
   ```

   Hoặc khai trong `config/log-viewer-remote.php` của app, chỉ cần nhóm `agent`. Provider gộp
   sâu nhóm này nên chỉ phải ghi key mình đổi.

3. Nên bật slow log ở channel riêng (`SLOW_LOG_CHANNEL=slow-log`, xem
   [slow-log.md](slow-log.md)). File nhỏ thì quét nhanh, và có thể mở quyền cho slow log mà
   không phải mở cả log lỗi.

**Trên máy dev**: khai host như khi xem log bằng UI (`LOG_VIEWER_HOSTS` hoặc
`config/log-viewer.php`), cộng `LOG_VIEWER_AGENT_TOKEN`. Kiểm tra:

```bash
php artisan log-viewer-remote:check
```

Cột **Agent** của lệnh check có thể hiện các giá trị sau:

| Cột Agent | Nghĩa |
|---|---|
| `v1.2.0 · agent · slow-log,daily` | Host đã nâng cấp và nhận agent token. Kèm danh sách channel cho phép |
| `chưa có (host < 1.2)` | Host trả HTML: chưa nâng cấp package, hoặc sai `route_path` |
| `403 — …` | Host chưa khai `LOG_VIEWER_AGENT_TOKEN`, hoặc hai bên lệch nhau |

## Bảo mật

- **Agent token chỉ đọc**:
  - nó chỉ hợp lệ ở `api/agent/*`, nơi chỉ có liệt kê file, gom số và đọc entry;
  - API của Log Viewer vendor (tải file, xoá file, xoá cache, danh sách host) và UI đều không nhận token này;
  - shared secret vẫn gọi được `api/agent/*`.
- **Giới hạn theo channel**:
  - Laravel không ghi tên channel vào từng dòng log, nên package đổi channel thành đường dẫn file (`single`, `daily`, `stack`, `monolog` có `stream`);
  - file agent đọc được là file Log Viewer liệt kê, giao với file thuộc channel cho phép;
  - giới hạn này áp cho cả agent token lẫn shared secret;
  - tham số `files` chỉ được chọn tên trong danh sách đó, không bao giờ mở đường dẫn do client gửi;
  - **đừng mở channel chứa dữ liệu nhạy cảm** (nội dung request AI, payload webhook, biến GraphQL…), vì kết quả đi thẳng tới agent.
- **Che dữ liệu**: email và chuỗi trông như token bị che trong khoá gom (`<email>`, `<token>`) và trong chữ của `entries`. Số và id được giữ nguyên để còn lần theo.
- **Giới hạn của token chỉ đọc**: agent chạy trên máy dev vẫn đọc được `.env`, gồm cả shared secret nếu máy dev có. Token chỉ đọc chống được việc lỡ tay, và dùng được ở chỗ không có shared secret. Nó không chống được một agent cố tình làm hại.
- Lệnh agent thiếu `LOG_VIEWER_AGENT_TOKEN` thì gửi shared secret của host, và in cảnh báo.

## Lệnh

| Lệnh | Việc |
|---|---|
| `log-viewer-remote:files --host=m1` | File được đọc, channel, ngày, dung lượng |
| `log-viewer-remote:aggregate --host=m1 …` | Gom số liệu (6 bảng), tự đi theo cursor tới khi xong |
| `log-viewer-remote:entries --host=m1 …` | Đọc trọn entry theo vị trí hoặc theo bộ lọc |

Bỏ `--host` (hoặc `--host=local`) thì chạy ngay trong process trên máy này.

**Chọn file**, theo một trong hai cách:
- `--files=slow-log-2026-10-05.log,…`: tên lấy từ lệnh `files`;
- `--channel=daily` (mặc định là channel của slow log), kèm `--date` hoặc `--from` / `--to` để chọn đúng các file daily trong khoảng.

**Thời gian.** Timestamp trong log theo `app.timezone` và không kèm offset. Ở app này là UTC, nên
"7 giờ sáng ngày 5/10 giờ VN" nằm ở file ngày 5/10 từ 00:00 UTC. Các dạng được nhận:

| Viết | Hiểu là |
|---|---|
| `--date=2026-10-05` | Cả ngày theo giờ người hỏi (`log-viewer.timezone`) |
| `--from="2026-10-05 07:00"` | Giờ người hỏi |
| `--from=2026-10-05T07:00+07:00` | Theo offset ghi kèm |
| `--from="-2 hours"`, `--from=yesterday` | Tương đối, theo Carbon |

Kết quả luôn ghi lại `window.from` / `window.to` theo giờ log để đối chiếu.

**Bảng gom** (`--only=`, mặc định có đủ):

| Bảng | Từ đâu | Hàng | Số |
|---|---|---|---|
| `levels` | mọi entry | — | entries, first, last, levels, scopes (WEB / CLI / other) |
| `messages` | mọi entry, thông điệp đã chuẩn hoá | `LEVEL \| thông điệp` | n, first, last, sample |
| `pages` | slow log WEB | trang (route / tên operation) | summaries, queries, ms, slow_queries, slow_ms, max_*, worst_duplicate |
| `sql_waste` | dòng nhóm của slow log tổng kết | SQL đã chuẩn hoá | waste = Σ(xN−1), count, ms, contexts, max_repeat, pages |
| `commands` | slow log CLI | tên job / lệnh | runs, queries, ms, max_ms, slow_queries, slow_ms |
| `slow_queries` | từng dòng query chậm | SQL đã chuẩn hoá | n, max_ms, first, last, pages |

`sample` = `file@offset` của lần gặp đầu. Đọc trọn entry đó bằng:

```bash
php artisan log-viewer-remote:entries --host=m1 --at=slow-log-2026-10-05.log@1124892
```

## Bảy thao tác hay làm

| # | Hỏi | Lệnh |
|---|---|---|
| 1 | Hôm qua lỗi gì, bao nhiêu | `aggregate --host=m1 --channel=daily --date=2026-10-05 --only=levels,messages` |
| 2 | Lỗi nào lặp nhiều nhất | như trên, đọc bảng `messages`; lấy `sample` → `entries --at=…` |
| 3 | Đọc trọn entry | `entries --at=file@offset`, hoặc `entries --channel=daily --contains="OOM" --limit=5` |
| 4 | Trang nào vượt ngưỡng nhiều nhất | `aggregate --only=pages` (summaries + slow_queries) |
| 5 | Query thừa (N+1) | `aggregate --only=sql_waste`; cột `pages` cho biết trang / lệnh sinh ra |
| 6 | Trang nào tốn DB nhất | `aggregate --only=pages`, xếp theo `queries` / `ms` |
| 7 | Job / lệnh nào tốn DB nhất | `aggregate --only=commands` |

`entries` có thêm các bộ lọc:
- `--regex='pg_sleep\(\?\)'`: PCRE, không cần dấu phân cách, không phân biệt hoa thường;
- `--level=error,alert`;
- `--limit`, `--max-bytes`.

Còn kết quả thì lệnh in sẵn `--cursor=…` để đọc tiếp.

## Đọc số cho đúng

- **Đếm `n`, đừng lấy trung bình** của query chậm. Chỉ query vượt `time_to_log` mới được ghi, nên đây là đuôi phân phối đã bị cắt: thời gian luôn quanh mép ngưỡng dù query thật nhanh hay chậm. `n` tăng vọt trong khi `max_ms` đứng yên là bão hoà do khối lượng (N+1, thiếu cache), không phải query plan tồi. Vì vậy bảng `slow_queries` cố ý không có avg.
- **Σ(xN−1)** là số query thừa: query lặp 41 lần trong một request là 40 lượt thừa. Chỉ đếm được các query nằm trong `top_queries` dòng đầu của mỗi tổng kết, vì log không ghi phần còn lại.
- **Gộp theo nhóm trang, không theo URL lẻ.** `/khoa-hoc/<slug>` là hàng nghìn URL; xếp theo URL lẻ thì không nhóm nào vào nổi top. Khoá trang được chọn theo thứ tự:
  1. key context trong `agent.page_key`. Mặc định là `req.route`; host GraphQL nên đặt `graphql.name` trước, vì ở đó URL nào cũng là `/graphql`;
  2. URL quy về route của app (router);
  3. luật thay số / token và `agent.url_groups`.
- `livewire ← /learn/{slug}`: request Livewire (`/livewire-…/update`) được gộp theo trang ở referer.
- `CLI ?`: dòng query chậm ở CLI của log cũ, trước khi slow log ghi tên job / lệnh.
- `(N khoá, bỏ M khoá nhỏ)`: bảng có trần `agent.cap`; vượt 2× trần thì cắt về top, nên khoá lớn xuất hiện muộn vẫn còn. `…` trong cột `pages` là tổng của các trang nhỏ.
- `cached`: kết quả lấy từ cache. Chỉ file đã đóng (ngày cũ, hoặc hơn 10 phút không ghi) mới được cache.

## Cursor, trần thời gian, host bận

- Mỗi request đọc tối đa `agent.max_seconds` (mặc định 20 s, phải nhỏ hơn timeout HTTP). Hết giờ thì host dừng ở đầu entry kế tiếp và trả `complete: false` cùng `cursor`.
- Cursor giữ trạng thái **ở server**: vị trí cộng toàn bộ bảng gom. Bảng top-N đã cắt thì không cộng dồn lại được ở phía client.
- Cursor chỉ dùng **một lần**, sống 10 phút (`cursor_ttl`), và gắn với đúng bộ tham số đã sinh ra nó. Hết hạn thì nhận 410; gửi sai tham số thì nhận 422.
- Lệnh `aggregate` tự gọi tiếp theo cursor.
- Mỗi host chỉ chạy `agent.slots` lượt quét cùng lúc (mặc định 2). Hết chỗ thì host trả 429 kèm `Retry-After`; lệnh tự chờ rồi thử lại, tối đa 6 lần.
- File đang được ghi thì entry cuối có thể đang ghi dở; nó được đọc như đã xong.

## HTTP API

Mọi endpoint là `GET {host}/{route_path}/api/agent/<action>` với header `Authorization: Bearer <token>`.

| Action | Tham số | Trả |
|---|---|---|
| `ping` | — | `ok, version, auth (agent\|shared), channels, aggregators, log_timezone, input_timezone, limits` |
| `files` | — | `channels, log_timezone, files[{name, channel, date, size, modified_at}]` |
| `aggregate` | `files` \| `channel`, `date`, `from`, `to`, `only`, `top` (1–200), `cursor`, `partial=1` | `complete, cursor, cached, window, stats, results` |
| `entries` | `at=file@offset` \| (`files` \| `channel`, `date`, `from`, `to`, `contains`, `regex`, `level`, `limit`, `max_bytes`, `cursor`) | `entries[{at, datetime, level, length, truncated, text}], next, complete` |

`aggregate` chỉ kèm `results` khi `complete` (hoặc `partial=1`).

`stats` gồm: `entries`, `scanned_bytes`, `total_bytes`, `percent_scanned`, `elapsed_ms`, `peak_memory_mb`, `files[{name, size, reached}]`.

Lỗi trả về dạng `{"error": "…", …}`:

| Mã | Khi nào |
|---|---|
| 403 | Token sai, hoặc file / channel không được phép (kèm `allowed_channels`) |
| 404 | Channel không có file trong khoảng đã chọn |
| 410 | Cursor hết hạn hoặc đã dùng |
| 422 | Tham số sai: thời gian, `only`, regex, cursor của bộ tham số khác |
| 429 | Host đủ lượt quét (`retry_after`) |

```bash
curl -s -H "Authorization: Bearer $LOG_VIEWER_AGENT_TOKEN" \
  'https://m1.example.com/log-viewer/api/agent/aggregate?channel=slow-log&date=2026-10-05&only=pages,sql_waste&top=10'
```

## Cấu hình (`log-viewer-remote.agent`)

| Key | Env | Mặc định | Ý nghĩa |
|---|---|---|---|
| `token` | `LOG_VIEWER_AGENT_TOKEN` | — | Token chỉ đọc |
| `channels` | `LOG_VIEWER_AGENT_CHANNELS` | channel của slow log | Channel được đọc |
| `max_seconds` | `LOG_VIEWER_AGENT_MAX_SECONDS` | 20 | Trần mỗi request |
| `max_bytes` | — | 0 | Trần byte mỗi request, 0 = chỉ giới hạn thời gian |
| `slots` | — | 2 | Số lượt quét đồng thời |
| `head_bytes` / `tail_bytes` | — | 64 KB / 8 KB | Phần giữ lại của entry dài |
| `slack` | — | 120 | Nới biên from / to (giây), vì nhiều worker ghi lệch giờ nhau |
| `cap` | — | 2000 | Số khoá mỗi bảng |
| `page_key` | — | `['req.route']` | Khoá trang, theo thứ tự ưu tiên |
| `url_groups` | — | `[]` | regex => thay thế cho path URL |
| `cache_ttl` / `cursor_ttl` | — | 86400 / 600 | Giây |
| `entries_limit` / `entry_max_bytes` | — | 50 / 16 KB | Trần của `entries` |

`timeout.agent` (40 s) là timeout HTTP của lệnh trên máy dev, phải lớn hơn `max_seconds`.

## Cách đọc file

- Tách entry theo **dòng header** `[datetime] env.LEVEL:` (8 level của Monolog), không theo ký tự xuống dòng.
- Vòng đọc chỉ tìm header. Chỉ entry cần chữ (slow log) mới được `fread` lại đúng đoạn byte của nó. Bản đầu gom chữ của mọi entry, mất 7,7 s; bản này 3,9 s cho cả 6 bảng.
- Reader của Log Viewer vendor dựng một object cho mỗi entry, chậm hơn khoảng 16 lần khi phải đi hết file.
- Context JSON ở cuối entry được tách bằng cách quét ngược và cân ngoặc, nên chịu được context lồng nhau, dấu nháy đã thoát, và xuống dòng thật do Laravel bật `allowInlineLineBreaks`.
- Slow log định dạng mới có context máy đọc. Log cũ, kể cả của app tự viết SqlLogger cùng dòng (`[mysql]`), thì đọc theo phần chữ.

So với `grep` trên cùng file 342 MB:

| Cách | Thời gian |
|---|---|
| `grep -c` header | 0,2 s |
| `grep -E` đủ mẫu header | 0,7 s |
| Bộ đọc PHP (chỉ tìm header) | 2,4 s |
| Bộ đọc PHP, cả 6 bảng gom | 3,9 s |

Chưa dùng `grep` vì:
- phải `proc_open` trong request web trên production (nhiều server chặn qua `disable_functions`);
- `grep` không tách được entry nhiều dòng;
- slow log đã ở channel riêng nên file nhỏ.

## Chưa làm

- **`grep` làm nguồn header**, khi cần quét log lỗi rất lớn. Chỉ phải thay `EntryReader::headers()`, giữ đường PHP làm dự phòng.
- **Cache tăng dần cho file đang ghi.** Trạng thái cursor đã đủ để quét tiếp từ chỗ cũ.
- **Forward qua `?host=`** từ host đang xem. Hiện lệnh gọi thẳng từng host.
- **MCP server** cho agent không dùng Bash.
- `entries --contains` chỉ tìm trong phần đầu và phần cuối đã giữ của entry dài.
