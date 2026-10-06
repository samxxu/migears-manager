---
id: "README"
---

# Issues — migears-manager

This directory is the record. Each item is one file: `issues/<id>.md`, with a front-matter header the
tooling owns and an appended thread below the finding. `../ISSUES.md` is only the generated summary of what is here.

## How an item moves

| status | who sets it | meaning | waiting on |
|---|---|---|---|
| `open` | the coordinator, at filing | filed, nobody has answered yet | the owner |
| `accepted` | the module owner; the coordinator when it passes on a ruling the user gave | agreed, the fix is pending | the owner |
| `question` | the module owner | a decision is needed before anything moves | the coordinator |
| `rejected` | the module owner | disputed; the reviewer answers with evidence or accepts it | the reviewer |
| `deferred` | the module owner; the coordinator when it passes on a ruling the user gave | deliberate, with a reason | - |
| `fixed` | the module owner; the coordinator when it passes on a fix the owner has not yet answered, which stays the owner's to confirm | believed fixed; the reviewer verifies it against the code | the reviewer |
| `verified` | the coordinator, from the reviewer's verdict | the fix was checked | - |
| `closed` | the coordinator, from the reviewer's verdict | nothing further; `resolution` is set | - |

Thread lines are appended, never rewritten. Sign every entry (§2 of
`.ai-engineering/skills/full-review/references/collaboration-protocol.md`).
`new-evidence` is a thread word, not a state: it adds facts without moving the item, so the front matter
keeps whatever status the item was already in. The table is rendered from the one vocabulary in
`issue_store.py`, so it cannot drift from what the tooling enforces.

## Standing notice

**Never write into `../ISSUES.md`.** It is generated from the items in this directory and is rewritten
wholesale on every filing, so anything typed there is lost. The record is `issues/`, and this is where
the channel lives.

1. **An owner writes inside its own module and nowhere else.** Its code, its docs and this directory are its
   own; a remark about another module goes into the coordinator's inbox, the document `project.json` names as
   `documents.coordinator_inbox`, and the coordinator reviews it and files it where it belongs. Writing into
   another module's `issues/` yourself is out of bounds even when the finding is right.
2. Land it on an item. If it concerns an item that exists, append to that item's thread in
   `issues/<id>.md`. If it is a new problem, file it as a new item through the tooling: ids are allocated
   by the tooling and are never written by hand.
3. Sign it: your role and scope, so a reader can tell your entry from another party's — one of these:
   `owner — migears-<module>`, `coordinator — cross-module`, `reviewer — migears-full-review`. Signing is mandatory: an unsigned entry cannot be
   traced back.
4. Sign every conclusion with one status word: `accepted` / `fixed` / `rejected` / `deferred` /
   `question` / `new-evidence`. The first five move the item, so the front matter's `status` becomes that
   word; `new-evidence` only adds facts. An unsigned entry may be re-graded as a new finding next round.
5. Read the items before starting work: evaluate every open one on its evidence, signed entries
   included, then execute the ones you accept together with your own work in one pass. Every item gets
   a status word.

---

# Issues — migears-manager（中文）

本目录就是记录。每条条目一个文件：`issues/<id>.md`，头部前置字段由工具拥有，问题之下是追加的讨论串。
`../ISSUES.md` 只是本目录内容的生成概览。

## 条目如何流转

| 状态 | 由谁设置 | 含义 | 在等谁 |
|---|---|---|---|
| `open` | 协调人，立案时 | 已立案，尚无人回复 | 模块主 |
| `accepted` | 模块主；用户给出裁定后由协调人转达时 | 认同，待修复 | 模块主 |
| `question` | 模块主 | 需要先做决策才能推进 | 协调人 |
| `rejected` | 模块主 | 不认同；评审方以证据反驳或采纳为误报 | 评审方 |
| `deferred` | 模块主；用户给出裁定后由协调人转达时 | 有意暂缓，附理由 | - |
| `fixed` | 模块主；模块主尚未作答、由协调人转达的修复，仍由模块主确认 | 认为已修；评审方对照代码核实 | 评审方 |
| `verified` | 协调人，依据评审方结论 | 修复已核实 | - |
| `closed` | 协调人，依据评审方结论 | 无需再动；同时写入 `resolution` | - |

讨论串只追加，不重写。每条都要署名（`.ai-engineering/skills/full-review/references/collaboration-protocol.md` §2）。
`new-evidence` 是讨论串用词，不是状态：它补充事实而不推动条目，因此前置字段保持该条目原有的状态。
本表由 `issue_store.py` 里那份唯一词表渲染，因此不会与工具实际执行的规则脱节。

## 长期说明

**绝不要往 `../ISSUES.md` 里写。** 它由本目录的条目生成，每次立案都整段重写，写进去的字会丢。记录在
`issues/`，渠道也在这里。

1. **模块主只在自己模块里写，别处不写。** 它的代码、它的文档与这个目录归它自己；关于别的模块的留言写进
   协调人的留言文档，即 `project.json` 以 `documents.coordinator_inbox` 命名的那个文件，由协调人复核后立到
   它该在的地方。即便 finding 是对的，自己写进别人的 `issues/` 也算越界。
2. 落在条目上。若针对某条已存在的条目，追加到该条目 `issues/<id>.md` 的讨论串；若是一个新问题，经工具
   立成新条目——编号由工具分配，绝不手写。
3. 署名：身份与范围，让读者能把你的条目与别方的分开——三者之一：
   `owner — migears-<module>`、`coordinator — cross-module`、`reviewer — migears-full-review`。署名是硬要求：没有署名的条目无法追溯来源。
4. 每条结论都带一个状态词：`accepted` / `fixed` / `rejected` / `deferred` / `question` /
   `new-evidence`。前五个会推动条目，因此前置字段的 `status` 成为那个词；`new-evidence` 只补充事实。
   没有署名的条目下一轮可能被按新发现重新评级。
5. 开工前先读条目：把每条开启条目按证据评估（签名条目也算），再把你接受的条目与自己的工作一并执行，
   不要拆成两轮。每条都要有状态词。
