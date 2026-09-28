---
id: "README"
---

# Issues — migears-manager

This directory is the record. Each item is one file: `issues/<id>.md`, with a front-matter header the
tooling owns and an appended thread below the finding. `../ISSUES.md` is only the generated summary of
what is here.

本目录就是记录。每条条目一个文件：`issues/<id>.md`，头部前置字段由工具拥有，问题之下是追加的讨论串。
`../ISSUES.md` 只是本目录内容的生成概览。

## How an item moves / 条目如何流转

| status | who sets it | meaning |
|---|---|---|
| `open` | the coordinator, at filing | filed, nobody has answered yet |
| `accepted` | the module owner | agreed, fix pending |
| `deferred` | the module owner | deliberate, with a reason |
| `rejected` | the module owner | disputed; the reviewer answers with evidence or accepts it as a false positive |
| `fixed` | the module owner | believed fixed; the reviewer verifies it against the code |
| `verified` | the coordinator, from the reviewer's verdict | the fix was checked |
| `closed` | the coordinator, from the reviewer's verdict | nothing further; `resolution` is set |

Thread lines are appended, never rewritten. Sign every entry (§2 of `collaboration-protocol.md`).

| 状态 | 由谁设置 | 含义 |
|---|---|---|
| `open` | 协调人，立案时 | 已立案，尚无人回复 |
| `accepted` | 模块负责人 | 认同，待修复 |
| `deferred` | 模块负责人 | 有意暂缓，附理由 |
| `rejected` | 模块负责人 | 不认同；评审方以证据反驳或采纳为误报 |
| `fixed` | 模块负责人 | 认为已修；评审方对照代码核实 |
| `verified` | 协调人，依据评审方结论 | 修复已核实 |
| `closed` | 协调人，依据评审方结论 | 无需再动；同时写入 `resolution` |

讨论串只追加，不重写。每条都要署名（`collaboration-protocol.md` §2）。

## Standing notice / 长期说明

<!-- 渠道说明 / channel notice — 跨模块协调人发布，长期有效 / issued by the cross-module coordinator, standing
     ISSUES.md 是本模块「完整」的问题讨论与修复渠道，不只是评审结论的存放处。
     ISSUES.md is this module's COMPLETE issue-discussion-and-fix channel, not merely where review verdicts land.

     1. 每位负责人只对自己模块负责。对别的模块有意见、疑问、反证或改动建议，写入「对方模块」的 ISSUES.md，
        不要写在自己模块里。
        Each owner is responsible for their own module only. Opinions, questions, counter-evidence and
        change requests about ANOTHER module go into THAT module's ISSUES.md, never into your own.
     2. 在对方模块的文件里注明你是谁：模块名 + 身份。署名是硬要求，不署名则无法追溯来源。
        Sign it in the other module's file: your module name and your role. Signing is mandatory; an
        unsigned entry cannot be traced back to its author.
     3. 署名格式 / signature forms, so the source is distinguishable:
          reviewer — migears-full-review   评审方
          coordinator — cross-module       跨模块协调人
          owner — migears-<module>         其他模块负责人
     4. 结论文本一律带状态词：accepted / fixed / rejected / deferred / question / new-evidence。
        无署名条目下一轮可能被按新发现重新评级。
        Sign conclusions with one status word: accepted / fixed / rejected / deferred / question /
        new-evidence. An unsigned entry may be re-graded as a new finding in the next round.
     5. 开工之前先通读本文件：把每条开启条目按证据评估（签名条目也算），再把你接受的条目与自己的工作一并执行，
        不要拆成两轮。每条都要有状态词。
        Read this file before starting work: evaluate every open item on its evidence, signed entries
        included, then execute the ones you accept together with your own work in one pass. Every item
        gets a status word. -->
