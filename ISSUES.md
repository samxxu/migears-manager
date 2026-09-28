# migears-manager — Known Issues / 已知问题

> Summary of this module's issues. The items themselves are in [`issues/`](issues/README.md), one file
> per item: a front-matter header and a thread. This file is generated from them and can be rewritten at
> any time; edit an item, never this file.
>
> 本模块问题的概览。条目本体在 [`issues/`](issues/README.md)，一条目一文件：前置字段加讨论串。
> 本文件由条目生成，随时可以整段重写；请改条目，不要改本文件。
>
> From the miGears Full-Module Code Review Report (4th round, 2026-09-27).

| | |
|---|---|
| Status / 状态 | **P0 cleared / P0 已清零** |
| Size / 体量 | src 240 lines (72 net) · 29 tests · 2 src files |

Legend / 图例 — **P0** functional or security · **P1** documentation that fails when copied · **P2** robustness · **P3** metadata and docs
级别说明 — **P0** 功能性或安全级 · **P1** 文档照抄即错 · **P2** 健壮性 · **P3** 元数据与文档

## At a glance / 状态一览

| | |
|---|---|
| Items / 条目 | P0 0 · P1 0 · P2 1 · P3 1 · other 0 |
| Answered / 已回复 | 1 of 2 |
| Waiting / 等待回复 | `P2-1` |

| id | level | status | title |
|---|---|---|---|
| [`P2-1`](issues/P2-1.md) | P2 | **open** | The write-then-emit window is still real: a synchronous listener that … |
| [`P3-1`](issues/P3-1.md) | P3 | **rejected** | Documentation mass is out of proportion: the README is 1,841 lines … |

## Verdict / 结论

A 240-line module whose contract is now stated explicitly rather than implied. The one data-consistency risk is documented rather than removed, which is defensible, but it stays a real risk for anyone wiring synchronous non-idempotent listeners.

240 行的模块，契约从「隐含」变成了「写明」。唯一的数据一致性风险是被文档化而不是被消除，这可以接受，但对「同步 + 非幂等监听器」的使用者仍是真实风险。

## Fixed since the last round / 本轮已修复确认

上一轮 5 项全部收口：README 删掉不存在的 boot() 示范并明说「没有任何东西会替你调用它」、把静态总线标为唯一刻意例外、为「写库后 emit 失败」单列一段讨论并给出幂等出路、EventBus 改为 final、BaseManager 补上 VERSION。 

## Test gaps / 测试盲区

No regression test for a listener throwing out of a manager use case (the duplicate-write path is documented only); no test for wiring twice accumulating listeners.

无「监听器抛异常穿出 Manager 用例」的回归用例（重复写入路径只有文档）；无「重复 wiring 导致监听器累积」的用例。

## Verification protocol / 验证方式

- `./vendor/bin/phpunit` · `composer analyse` · `composer validate`
- Warning/notice/deprecation/risky flags in `phpunit.xml.dist`: all four on
- A PHP warning counts as a test failure only where those flags are on; otherwise run `./vendor/bin/phpunit --fail-on-warning` explicitly.
- 只有在上述开关打开时 PHP 警告才会导致套件失败；否则请显式加 `--fail-on-warning`。
