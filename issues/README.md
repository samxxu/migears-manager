---
id: "README"
---

# Issues — migears-manager

This directory is the record. Each item is one file: `issues/<id>.md`, with a front-matter header the
tooling owns and an appended thread below the finding. `../ISSUES.md` is only the generated summary of what is here.

本目录就是记录。每条条目一个文件：`issues/<id>.md`，头部前置字段由工具拥有，问题之下是追加的讨论串。
`../ISSUES.md` 只是本目录内容的生成概览。

## How an item moves / 条目如何流转

| status | who sets it | meaning | waiting on |
|---|---|---|---|
| `open` | the coordinator, at filing | filed, nobody has answered yet | the owner |
| `accepted` | the module owner | agreed, the fix is pending | the owner |
| `question` | the module owner | a decision is needed before anything moves | the coordinator |
| `rejected` | the module owner | disputed; the reviewer answers with evidence or accepts it | the reviewer |
| `deferred` | the module owner | deliberate, with a reason | - |
| `fixed` | the module owner | believed fixed; the reviewer verifies it against the code | the reviewer |
| `verified` | the coordinator, from the reviewer's verdict | the fix was checked | - |
| `closed` | the coordinator, from the reviewer's verdict | nothing further; `resolution` is set | - |

Thread lines are appended, never rewritten. Sign every entry (§2 of `collaboration-protocol.md`).
`new-evidence` is a thread word, not a state: it adds facts without moving the item, so the front matter
keeps whatever status the item was already in. The table is rendered from the one vocabulary in
`issue_store.py`, so it cannot drift from what the tooling enforces.

| 状态 | 由谁设置 | 含义 | 在等谁 |
|---|---|---|---|
| `open` | 协调人，立案时 | 已立案，尚无人回复 | 负责人 |
| `accepted` | 模块负责人 | 认同，待修复 | 负责人 |
| `question` | 模块负责人 | 需要先做决策才能推进 | 协调人 |
| `rejected` | 模块负责人 | 不认同；评审方以证据反驳或采纳为误报 | 评审方 |
| `deferred` | 模块负责人 | 有意暂缓，附理由 | - |
| `fixed` | 模块负责人 | 认为已修；评审方对照代码核实 | 评审方 |
| `verified` | 协调人，依据评审方结论 | 修复已核实 | - |
| `closed` | 协调人，依据评审方结论 | 无需再动；同时写入 `resolution` | - |

讨论串只追加，不重写。每条都要署名（`collaboration-protocol.md` §2）。
`new-evidence` 是讨论串用词，不是状态：它补充事实而不推动条目，因此前置字段保持该条目原有的状态。
本表由 `issue_store.py` 里那份唯一词表渲染，因此不会与工具实际执行的规则脱节。

## Standing notice / 长期说明

**Never write into `../ISSUES.md`.** It is generated from the items in this directory and is rewritten
wholesale on every filing, so anything typed there is lost. The record is `issues/`, and this is where
the channel lives.

**绝不要往 `../ISSUES.md` 里写。** 它由本目录的条目生成，每次立案都整段重写，写进去的字会丢。记录在
`issues/`，渠道也在这里。

1. You are responsible for your own module only. An opinion, a question, a piece of counter-evidence or a
   request for change about ANOTHER module needs the user's approval first, and then goes into THAT
   module's `issues/` directory, never into your own.
2. Land it on an item. If it concerns an item that exists, append to that item's thread in
   `issues/<id>.md`. If it is a new problem, hand it to the coordinator to file as a new item: ids are
   allocated by the tooling and are never written by hand.
3. Sign it: your module name and your role (`owner — migears-<module>`), so a reader can tell a peer
   remark from a reviewer finding. Signing is mandatory; an unsigned entry cannot be traced back.
4. Sign every conclusion with one status word: `accepted` / `fixed` / `rejected` / `deferred` /
   `question` / `new-evidence`. The first five move the item, so the front matter's `status` becomes that
   word; `new-evidence` only adds facts. An unsigned entry may be re-graded as a new finding next round.
5. Read the items before starting work: evaluate every open one on its evidence, signed entries
   included, then execute the ones you accept together with your own work in one pass. Every item gets
   a status word.

---

**绝不要往 `../ISSUES.md` 里写。** 它由本目录的条目生成，每次立案都整段重写，写进去的字会丢。记录在
`issues/`，渠道也在这里。

1. 你只对自己模块负责。对**别的**模块有意见、疑问、反证或改动建议，先请示用户，然后写进**那个**模块的
   `issues/` 目录，不要写在自己模块里。
2. 落在条目上。若针对某条已存在的条目，追加到该条目 `issues/<id>.md` 的讨论串；若是一个新问题，交给
   协调人立成新条目——编号由工具分配，绝不手写。
3. 署名：模块名加身份（`owner — migears-<module>`），让读者能把同级留言与评审 finding 分开。署名是
   硬要求；没有署名的条目无法追溯来源。
4. 每条结论都带一个状态词：`accepted` / `fixed` / `rejected` / `deferred` / `question` /
   `new-evidence`。前五个会推动条目，因此前置字段的 `status` 成为那个词；`new-evidence` 只补充事实。
   没有署名的条目下一轮可能被按新发现重新评级。
5. 开工前先读条目：把每条开启条目按证据评估（签名条目也算），再把你接受的条目与自己的工作一并执行，
   不要拆成两轮。每条都要有状态词。


