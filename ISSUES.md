# migears-manager — Known Issues

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> From the miGears Full-Module Code Review Report (5th round, 2026-09-28).

| | |
|---|---|
| Status | **P2 open** |
| Size | src 99 lines (net) · 31 tests · 3 src files |

Legend — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs

## At a glance

| | |
|---|---|
| Unsettled | P0 0 · P1 0 · P2 1 · P3 1 · other 0 |
| Settled | 0 of 2 |
| Waiting on the owner | `P2-1` |
| Waiting on the reviewer | `P3-1` |
| Waiting on the coordinator | _nothing_ |
| Deferred, owing nobody | _nothing_ |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **open** | The write-then-emit window is still real: a synchronous listener that … |
| [`P3-1`](issues/P3-1.md) | P3 | **rejected** | Documentation mass is out of proportion: the README is 1,841 lines … |

## Unclosed

What is left to do here: every item whose `status` is not `verified` or `closed`,
highest severity first. `waiting on` is the party who acts next, read from that status.

| | |
|---|---|
| Unclosed | **2** of 2 |
| By status | `open` 1 · `rejected` 1 |
| Waiting on | owner 1 · reviewer 1 |

| level | item | status | waiting on | title |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `open` | owner | The write-then-emit window is still real: a synchronous listener that … |
| **P3** | [`P3-1`](issues/P3-1.md) | `rejected` | reviewer | Documentation mass is out of proportion: the README is 1,841 lines … |

## Verdict

A lean manager-layer base with a synchronous event bus; the one open P2 is an acknowledged design trade-off (write-then-emit window) with idempotency as the documented answer.

## Fixed since the last round

P2-1 remains open by design (documented trade-off with idempotency as the prescribed solution); P3-1 doc-mass item rejected as a style preference.

## Test gaps

No test for SideEffectFailedException being thrown from emit() when multiple listeners exist and a middle one throws; no test for EventBus reset() during an active emit (re-entrancy edge case).

## Verification protocol

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.


---

# migears-manager — 已知问题

> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> 出自 miGears 全模块代码评审报告（5th round，2026-09-28）。

| | |
|---|---|
| 状态 | **P2 待修** |
| 体量 | src 99 行（净）· 31 个用例 · 3 个源文件 |

级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## 状态一览

| | |
|---|---|
| 未了结 | P0 0 · P1 0 · P2 1 · P3 1 · 其他 0 |
| 已了结 | 0 / 2 |
| 等负责人 | `P2-1` |
| 等评审方 | `P3-1` |
| 等协调人 | _无_ |
| 已暂缓，不欠谁 | _无_ |

| id | 级别 | 状态 | 标题 |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **open** | 「先写库后 emit」的窗口仍然存在：同步监听器抛异常会穿出用例，而数据已落库，入口层重试即重复写入。README … |
| [`P3-1`](issues/P3-1.md) | P3 | **rejected** | 文档体量失衡：README 1,841 行对应 240 行源码（约 … |

## 未关闭

本模块还剩什么要做：所有 `status` 不是 `verified` 或 `closed` 的条目，按严重度从高到低。
`waiting on` 是下一步该动手的一方，由其状态读出。

| | |
|---|---|
| 未关闭 | **2** / 2 |
| 按状态 | `open` 1 · `rejected` 1 |
| 等在谁 | 负责人 1 · 评审方 1 |

| 级别 | 条目 | 状态 | 等在谁 | 标题 |
|---|---|---|---|---|
| **P2** | [`P2-1`](issues/P2-1.md) | `open` | 负责人 | 「先写库后 emit」的窗口仍然存在：同步监听器抛异常会穿出用例，而数据已落库，入口层重试即重复写入。README … |
| **P3** | [`P3-1`](issues/P3-1.md) | `rejected` | 评审方 | 文档体量失衡：README 1,841 行对应 240 行源码（约 … |

## 结论

精简的 Manager 层基类，带同步事件总线；唯一开放的 P2 是已承认的设计取舍（先写后 emit 窗口），文档规定用幂等性来应对。

## 本轮已修复确认

P2-1 remains open by design (documented trade-off with idempotency as the prescribed solution); P3-1 doc-mass item rejected as a style preference.

## 测试盲区

无测试验证多个监听器时中间那个抛出异常后 emit() 抛出 SideEffectFailedException；无测试验证 EventBus 在活跃 emit 期间调用 reset()（重入边界情况）。

## 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- `phpunit.xml.dist` 中的 warning/notice/deprecation/risky 开关：四个全开
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
