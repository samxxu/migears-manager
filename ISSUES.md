# migears-manager — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (6th round, 2026-10-01).

| | |
|---|---|
| Status | **Best state** |
| Size | src 99 lines (net) · 31 tests · 3 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 0 · P3 3 · other 0 |
| Settled | 1 of 4 |
| Waiting on the owner | _nothing_ |
| Waiting on the coordinator | `P3-3` |
| Waiting on the reviewer | `P3-1`, `P3-2` |
| Deferred, owing nobody | _nothing_ |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | The write-then-emit pattern leaves a window where the write succeeds … |
| [`P3-1`](issues/P3-1.md) | P3 | **rejected** | Documentation mass is out of proportion: the README is 1,841 lines … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | EventBus::emit() docblock claims 'The listener list is snapshotted when … |
| [`P3-3`](issues/P3-3.md) | P3 | **question** | `public const VERSION` at `src/BaseManager.php:59` has no reader … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **3** of 4 |
| By status | `question` 1 · `rejected` 2 |
| Waiting on | coordinator 1 · reviewer 2 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `rejected` | reviewer | Documentation mass is out of proportion: the README is 1,841 lines … |
| **P3** | [`P3-2`](issues/P3-2.md) | `rejected` | reviewer | EventBus::emit() docblock claims 'The listener list is snapshotted when … |
| **P3** | [`P3-3`](issues/P3-3.md) | `question` | coordinator | `public const VERSION` at `src/BaseManager.php:59` has no reader … |

## Verdict

The window is a documented boundary rather than an unstated one, and the remaining items are design decisions that were answered correctly rather than defects.

## Fixed since the last round

P2-1 verified: the write-then-emit window is now documented in both README halves as a deliberate boundary ("already been committed… nothing in this package closes it") with the code untouched, which is what the 2026-09-29 ruling asked for.

## Test gaps

EventBus is a process-level singleton isolated by reset() in eight places, so there is no cross-test leakage; the "off() removes only the first match" and "emit() has no re-entrancy guard" promises have no tests, which is a documentation gap rather than a functional one.

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-manager — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（6th round，2026-10-01）。

| | |
|---|---|
| 状态 | **状态最好** |
| 体量 | src 99 行（净）· 31 个用例 · 3 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 0 · P3 3 · 其他 0 |
| 已了结 | 1 / 4 |
| 等模块主 | _无_ |
| 等协调人 | `P3-3` |
| 等评审方 | `P3-1`, `P3-2` |
| 已暂缓，不欠谁 | _无_ |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **verified** | 先写后 emit … |
| [`P3-1`](issues/P3-1.md) | P3 | **rejected** | 文档体量失衡：README 1,841 行对应 240 行源码（约 … |
| [`P3-2`](issues/P3-2.md) | P3 | **rejected** | EventBus::emit() 文档注释称「监听器列表在调用开始时被快照」，但实现直接遍历 $this->listeners[$event] … |
| [`P3-3`](issues/P3-3.md) | P3 | **question** | `src/BaseManager.php:59` 的 `public const VERSION` … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **3** / 4 |
| 按状态 | `question` 1 · `rejected` 2 |
| 等在谁 | 协调人 1 · 评审方 2 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P3** | [`P3-1`](issues/P3-1.md) | `rejected` | 评审方 | 文档体量失衡：README 1,841 行对应 240 行源码（约 … |
| **P3** | [`P3-2`](issues/P3-2.md) | `rejected` | 评审方 | EventBus::emit() 文档注释称「监听器列表在调用开始时被快照」，但实现直接遍历 $this->listeners[$event] … |
| **P3** | [`P3-3`](issues/P3-3.md) | `question` | 协调人 | `src/BaseManager.php:59` 的 `public const VERSION` … |

## 结论

该窗口现已是写在文档里的边界而非默认行为，其余条目都是被正确作答的设计决定，不是缺陷。

## 本轮已修复确认

P2-1 verified: the write-then-emit window is now documented in both README halves as a deliberate boundary ("already been committed… nothing in this package closes it") with the code untouched, which is what the 2026-09-29 ruling asked for.

## 测试盲区

EventBus 是进程级单例，靠八处 reset() 隔离，无跨用例串扰；「off() 只删首个匹配」与「emit() 无重入保护」两条承诺无用例钉住，属文档性盲区而非功能盲区。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
