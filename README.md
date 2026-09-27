# migears/manager

![Version](https://img.shields.io/badge/version-2.0.0-blue)

The business layer — it owns a module's business operations, writes through DAOs, and announces every side effect as an event.

> **Background**: miGears is the open-source successor of **TinyGears**, a
> self-developed PHP framework. It was renamed and open-sourced recently because
> the name *TinyGears* is already taken in the open-source community.

## Design spirit

A codebase needs exactly one place where a business rule can be written down. In miGears that place
is a Manager — **one class per business module**: the module's tables, the reads and writes over them,
and every operation that changes them. `OrderManager` is where the orders table lives, and it is also
where placing, cancelling and completing an order live. Inside the class, **each public method is one
use case**, run from its first line to its last so the caller has nothing left to finish.

The data those rules operate on is a **plain object that holds its own validation rules** — public
`readonly` fields, no base class, no getters, no mapper, and no knowledge of where it came from or
where it goes. Storage sits behind a **separate, thin boundary**: one DAO per table, direct SQL, no
layer of abstraction in between. And everything that is a side effect — mail, log lines, background
jobs, another module's records — is **announced as an event** and carried out by a listener, so the
Manager never learns those services exist.

What a Manager may reach out for is deliberately narrow: the configured objects the application put
together (a PDO, Redis, the logger, the mailer, and the DAOs built from them) arrive through **one
registry — a PSR-11 container** — and everything else — Domains, values, helpers — is simply
constructed. Nothing below the Manager ever sees that container: a DAO receives a PDO and a logger,
never a registry.

If you have met this shape in the enterprise-architecture literature — the part of domain-driven
design that is about keeping the model, the storage boundary and the notifications apart — this is
exactly that subset and nothing else. From here on this document does not use that vocabulary; it
shows the code.

| The rule | What it means here |
|---|---|
| **One Manager, one module** | `OrderManager` owns the orders table and everything that happens to an order. It does not own invoices, payments or accounts. |
| **One method, one use case** | `place()` runs from validation to the event it emits. The caller never has to finish the job. |
| **The Manager is the only door** | The module's DAO is resolved by its Manager and handed to nobody. Every read and write goes through the Manager. |
| **Configured objects come from the Registry** | The constructor takes the Registry and resolves what it needs, once, into private properties. |
| **Data in, Domain out** | Parameters are plain arrays and scalars; the return value is a Domain object. |
| **No external services** | Mail, SMS, HTTP calls, log lines, background jobs: emit an event and let a listener own them. |
| **Write, then notify** | Persist, re-read, `emit()`. An event states a fact that is already stored. |

Two consequences worth stating, because they explain most of the choices below:

- A Manager is as long as its module needs: a few dozen lines for a simple one, a few hundred when the module has many state changes and queries. Length is not the signal — **covering a second module is**. A Manager that starts reaching for invoices belongs in two classes, and the boundary between them is usually the moment it needs another module's tables.
- A Manager keeps no state between calls. Apart from what it resolved in the constructor, it remembers nothing — which is also why it is safe to build one per request.

## Two phases: wiring, then runtime

Everything in this package is arranged around one split. Assembly happens **once, at initialization**;
after that, nothing is configured, subscribed or guessed again.

```
Wiring — once, at initialization
    builds the Registry        pdo · redis · logger · mailer · each DAO · each Manager
    subscribes the listeners   BaseManager::listen()

Runtime — per request, per command
    Resource / Command / Task ──▶ Manager ──▶ Domain        fields and rules
                                    │     └─▶ DAO ──▶ SQL ──▶ PDO
                                    └─emit()─▶ listeners     mail, logs, jobs, other Managers
```

The runtime half is deliberately dumb: a Manager decides, writes and announces. It cannot tell whether
it was called from a web request, a cron command or a test, and that is the point — the same use case
cannot behave differently depending on how it was invoked.

## The registry: a PSR-11 container

The registry is what the application configured, addressed by id. It is **not** an auto-wiring
container: it builds nothing, it never inspects a constructor's types, and it never guesses. It has
two methods — and they are not ours. They are **PSR-11**, the `psr/container` package:

```php
interface ContainerInterface                       // Psr\Container\ContainerInterface
{
    public function has(string $id): bool;         // probe — for objects a Manager can do without
    public function get(string $id);               // obtain — must throw when the id is not registered
}
```

Using the standard interface rather than declaring our own is a deliberate dependency decision.
`psr/container` is interfaces only, and it is what lets this package and `migears/web` speak the same
contract **without depending on each other**: if the contract lived in this package, the web package
would have to require this one just to type-hint it.

`get()` **must throw** for an unknown id. That is PSR-11's own rule, and it is exactly what we want: a
missing id is an assembly mistake, so it fails where it is asked for instead of surfacing later as
"something is not an object". A container that returns `null` there breaks the contract, and the
fail-fast guarantee with it.

**What belongs in it** — objects that need configuring, exactly like the framework's own rule:

```
pdo · redis · logger · mailer · cache · config        connections, clients, credentials, paths
each DAO                                              built from those
each Manager                                          built from the Registry
```

**What does not** — anything that merely needs `new`:

```php
$order  = OrderDomain::fromArray($row);      // a value object
$total  = new Money($cents);                 // a value object
$errors = OrderDomain::validateArray($form); // nothing to configure
```

A Registry with value objects in it stops being "the configured things" and becomes a junk drawer
where every id is a guess.

### Who may depend on it

**Only Managers.** This is the rule that keeps the rest of the framework usable without any container:

```php
final class OrderManager extends BaseManager
{
    private readonly OrderDao $orders;
    private readonly OrderItemDao $orderItems;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);                      // the logger comes from here
        $this->orders = $registry->get(OrderDao::class);      // resolved once, in the constructor
        $this->orderItems = $registry->get(OrderItemDao::class);
    }
}
```

```php
final class OrderDao                                    // no Registry here, ever
{
    use SingleTableDao;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

Resolving in the constructor rather than inside each method matters for two reasons: what the Manager
is made of is visible at the top of the class, and a forgotten registration fails while the application
is booting instead of halfway through a request.

In an application there is one container for the whole application — usually the `MiRest` instance
itself, which implements the interface, or any other PSR-11 container. The wiring registers into it and
every Manager resolves from it; in a test the container is whatever two-method double the test needs.

## The skeleton

```php
use Psr\Container\ContainerInterface;
use MiGears\Manager\BaseManager;

final class OrderManager extends BaseManager
{
    private readonly OrderDao $orders;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);                       // the logger comes from here
        $this->orders = $registry->get(OrderDao::class);
    }

    public function place(array $form, int $actorId): OrderDomain
    {
        $errors = OrderDomain::validateArray($form);                 // 1. validate
        if ($errors !== []) {
            throw new UnprocessableEntityHttpException('INVALID_ORDER');
        }

        $id = $this->orders->insert([...]);                          // 2. write
        $order = OrderDomain::fromArray($this->orders->getByIdOrFail($id));   // 3. re-read

        $this->emit(OrderEvents::PLACED, $order);                    // 4. notify

        return $order;                                               // 5. a Domain goes back
    }
}
```

Five steps, and the order of them matters: validate before touching the database, re-read after
writing so that the object handed back is the stored one, notify last. The full body of `insert()` is
elided here; the concrete version appears under *Domains* and *DAOs* below.

Run it with a two-entry registry — no container, no bootstrap, no HTTP:

```php
$orders  = new InMemoryOrderDao();
$manager = new OrderManager(new ArrayContainer([
    OrderDao::class         => $orders,
    LoggerInterface::class  => new NullLogger(),
]));

$seen = [];
BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use (&$seen): void {
    $seen[] = $order->id;
});

$order = $manager->place(['user_id' => 1, 'title' => 'First order', 'amount' => 2500], actorId: 9);

$this->assertSame(1, $order->id);
$this->assertSame([1], $seen);
```

`BaseManager` is where the logger and `emit()` come from, so every Manager extends it. It gives nothing
else: no CRUD, no query helpers, no accessor for the Registry — a Manager uses the Registry in its own
constructor and then forgets it exists.

## Responsibilities

A Manager owns one business module: its tables, the reads and writes over them, and every operation
that changes their state. Each method is then a complete use case — it loads the Domains it needs,
decides, persists through DAOs, and emits what happened. That is a deliberately narrow job, and most of
the value comes from the part it is not allowed to do, because that is the part that would otherwise end
up duplicated across three layers with slightly different behaviour.

So a module's public surface has two kinds of method, and they belong on the same class:

```php
final class OrderManager extends BaseManager
{
    private readonly OrderDao $orders;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);
        $this->orders = $registry->get(OrderDao::class);
    }

    // --- reads and writes: the module's only entry point to its own table,
    //     and the place where a row becomes a Domain
    public function getById(int $orderId): ?OrderDomain
    {
        $row = $this->orders->getById($orderId);

        return $row === null ? null : OrderDomain::fromArray($row);
    }

    /** @return list<OrderDomain> */
    public function listByUser(int $userId): array
    {
        return array_map(
            fn(array $row) => OrderDomain::fromArray($row),
            $this->orders->getOpenByUserId($userId),
        );
    }

    public function count(): int
    {
        return $this->orders->count();
    }

    // --- behaviour: one method, one complete use case
    public function complete(int $orderId, int $actorId): OrderDomain
    {
        $order = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

        if ($order->status !== 'PLACED') {
            throw new ConflictHttpException('ORDER_NOT_COMPLETABLE');
        }

        $this->orders->update([
            'id'         => $orderId,
            'status'     => 'COMPLETED',
            'updated_at' => time(),
            'updated_by' => $actorId,
        ]);

        $completed = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

        $this->emit(OrderEvents::COMPLETED, $completed, $actorId);

        return $completed;
    }
}
```

A forwarding read is not a wasted method: it is where the row becomes a Domain, where the module's
caching choice gets made, and where the rest of the application's only door to that table sits. What it
must not be is a *second* door — if the entry layer is also allowed to reach `OrderDao` directly, the
façade has stopped paying for itself and the conventions will drift apart.

| It never does | Instead |
|---|---|
| Read `$_POST` / `$_FILES` / headers / the session | The entry layer maps input and passes an array plus scalars. |
| Build a response, pick a status code, format JSON | Return a Domain; the entry layer serialises it. |
| Write SQL, or hold a `PDO` "just in case" | Put the query in the DAO and reach the DAO through the Registry. |
| Send mail / SMS / HTTP calls, write log lines | Emit an event; `logger()` is there for diagnosis, not for notification. |
| Touch another module's DAO | Call that module's Manager, resolved from the Registry. |
| Keep a singleton, static registry or service locator in your own code | Inject the Registry and resolve in the constructor; the package's own bus is its only process-level state, and it is internal |
| Remember request state between calls | Pass it as a parameter. |
| Let anyone else hold, resolve or receive one of its DAOs | Keep the DAO a private property; the Manager is the module's only door to its tables. |

## Four habits, with the alternative

**Input: map it outside, not inside.** The Manager's parameters are its interface, and that interface
should be data. `$_POST` and the session belong to the entry layer, which is also the only place that
knows about status codes.

```php
// ✗ the Manager knows the transport
public function place(): OrderDomain
{
    $userId = (int) $_POST['user_id'];
}

// ✓ the Manager takes data
public function place(array $form, int $actorId): OrderDomain
```

**Queries: ask the DAO, not the database.** Once a Manager holds a `PDO` it will eventually write a
statement, and then the SQL for one table lives in two files. Every query gets one home.

```php
// ✗
$rows = $this->pdo->query("SELECT * FROM orders WHERE user_id = {$userId}");

// ✓
$rows = $this->orders->getOpenByUserId($userId);
```

**Side effects: emit them, do not perform them.** A Manager that sends mail itself cannot be tested
without SMTP, and has to be edited every time a notification channel changes. A Manager that emits an
event knows only that something happened.

```php
// ✗ the Manager knows about mail
$this->mailer->send(new Mail(to: [$user->email], subject: 'Your order has been placed'));

// ✓ the Manager knows that an order was placed
$this->emit(OrderEvents::PLACED, $order);
```

**Output: Domain objects, not arrays.** Callers get fields they can read and a type they can rely on,
and serialisation happens only where the response shape is actually decided.

```php
// ✗ every caller re-hydrates the row and guesses the keys
return $order->toArray();

// ✓
return $order;
```

## Domains

A Domain is what one business record looks like in code: fields, plus the rules that say whether those
fields are acceptable. Two conversions come from `migears/domain`:

```php
$order = OrderDomain::fromArray($this->orders->getByIdOrFail($id));   // row   → Domain
$row   = $order->toArray();                                            // Domain → row
```

The rules live on the object rather than in the Manager, so two use cases that touch the same record
cannot disagree about what a valid record is. The Manager only decides what to do with the answer —
here, refusing the request with an exception the entry layer already knows how to render:

```php
$errors = OrderDomain::validateArray($form);
if ($errors !== []) {
    throw new UnprocessableEntityHttpException('INVALID_ORDER');
}

// $errors is structured data, not a sentence, so it can be translated or returned as-is:
// ['title' => ['rule' => 'maxLength', 'params' => ['maxLength' => 100]]]
```

A concrete Domain, with its rules next to its fields:

```php
use MiGears\Domain\DataAccess;
use MiGears\Domain\Validatable;

final class OrderDomain
{
    use DataAccess;
    use Validatable;

    public function __construct(
        public readonly int $id,
        public readonly int $user_id,
        public readonly string $title,
        public readonly int $amount,
        public readonly string $status,
        public readonly int $created_at,
    ) {}

    protected static function validationRules(): array
    {
        return [
            'user_id' => ['required' => true, 'integer' => true, 'min' => 1],
            'title'   => ['required' => true, 'maxLength' => 100],
            'amount'  => ['required' => true, 'integer' => true, 'min' => 1],
        ];
    }
}
```

Related rows a Domain must not fetch by itself get their loader injected from the Manager, so the
accessor reads naturally while the dependency knowledge stays in the layer that has it
(see `migears/domain` → *Related Data*). There is no lifecycle hook to hang this on, and nothing
calls a `boot()` for you: the wiring builds each Manager once, before anything is served, so the
constructor is the place.

```php
final class OrderManager extends BaseManager
{
    private readonly OrderItemDao $orderItems;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);
        $this->orderItems = $registry->get(OrderItemDao::class);

        OrderDomain::setItemLoader(
            fn(OrderDomain $order) => $this->orderItems->getByOrderId($order->id),
        );
    }
}
```

One trap is worth naming, because it is silent until it hits the database: never park a collaborator
on a Domain. `toArray()` returns *every* property, including private ones, and that array is what the
DAO hands to SQL.

```php
// ✗
class OrderDomain { public function __construct(public readonly int $id, private ?OrderDao $dao) {} }

// toArray() → ['id' => 7, 'dao' => OrderDao]
// SQL        → ERROR: table orders has no column named dao
```

## DAOs

A DAO owns the SQL for one table, and nothing else: it receives rows and values, returns rows, and
neither knows nor cares which use case asked. Its constructor takes **concrete collaborators** — a PDO,
a logger, a Redis client — and never a Registry. That is what keeps a DAO usable in a plain script, a
migration or a test with no wiring at all.

A Manager injects one DAO per table **of its own module** — an order and its line items always move
together, so both arrive here; invoices never do. When a use case genuinely needs another module's data,
it resolves that module's **Manager**, never its DAO, and lets it write its own rows.

```php
public function __construct(ContainerInterface $registry)
{
    parent::__construct($registry);
    $this->orders     = $registry->get(OrderDao::class);
    $this->orderItems = $registry->get(OrderItemDao::class);
}
```

A concrete DAO. The trait covers ordinary CRUD; anything beyond it is written out in plain SQL in the
method that needs it, which is also where it can be read:

```php
use PDO;
use Psr\Log\LoggerInterface;
use MiGears\Dao\SingleTableDao;

final class OrderDao
{
    use SingleTableDao;

    protected string $table = 'orders';
    protected string $idColumn = 'id';

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }

    /** @return list<array<string, mixed>> */
    public function getOpenByUserId(int $userId): array
    {
        return $this->getSqlBuilder()
            ->select()
            ->from($this->table)
            ->where('`user_id` = :user_id AND `status` = :status', ['user_id' => $userId, 'status' => 'PLACED'])
            ->execute();
    }
}
```

Coordinating several DAOs is the Manager's job, and this is the full body of `place()` — the skeleton's
`insert([...])` expanded. Read it once for the decisions (are the fields valid? are there items? what is
the total?) and once for the persistence (one order row, then one row per item); the two never mix:

```php
public function place(array $form, int $actorId): OrderDomain
{
    $errors = OrderDomain::validateArray($form);
    if ($errors !== []) {
        throw new UnprocessableEntityHttpException('INVALID_ORDER');
    }

    $items = $form['items'] ?? [];
    if ($items === []) {
        throw new ConflictHttpException('ORDER_WITHOUT_ITEMS');
    }

    $total = array_sum(array_map(fn(array $i) => (int) $i['price'] * (int) $i['quantity'], $items));

    $orderId = $this->orders->insert([
        'user_id'    => (int) $form['user_id'],
        'title'      => trim((string) $form['title']),
        'amount'     => $total,
        'status'     => 'PLACED',
        'created_at' => time(),
        'created_by' => $actorId,
    ]);

    foreach ($items as $item) {
        $this->orderItems->insert([
            'order_id' => $orderId,
            'sku'      => $item['sku'],
            'price'    => (int) $item['price'],
            'quantity' => (int) $item['quantity'],
        ]);
    }

    $order = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

    $this->emit(OrderEvents::PLACED, $order);

    return $order;
}
```

**Transactions.** There is no hidden transaction manager to reason about, which is why the rule fits
in a table. In the common case — one statement, or several tables that always move together — the
question never reaches the Manager.

| Situation | Who owns it |
|---|---|
| One statement | Nobody — it is already atomic. |
| Two tables that always move together | The DAO: it owns the connection, so it owns `beginTransaction()` / `commit()` / `rollBack()`. |
| Several independent DAOs in one use case | The Manager, given the `PDO` explicitly. A deliberate cost — first ask whether the write belongs to one DAO. |

If a use case needs three DAOs inside one transaction, that usually means one of them is missing a
method, not that the Manager needs to own connections.

## Events

Anything that is true of the system and that somebody else might care about is an event. A Manager
announces it with one call and forgets about it:

```php
$this->emit(OrderEvents::PLACED, $order);
```

Who listens is decided somewhere else entirely, at initialization:

```php
BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($registry): void {
    $registry->get(InvoiceManager::class)->issueFor($order);
});
```

The bus behind those two calls is **internal to this package**. You never type-hint it, never register
it, never pass it to a constructor and never put it in the Registry. `BaseManager` is the whole public
surface: `listen()` for the wiring, `emit()` for Managers.

That bus is the package's only process-level state, and the exception is deliberate: one bus per
process is what lets a listener registered by the wiring be seen by every Manager, and no business code
ever holds a reference to it. Nothing else here keeps static state — a Manager still receives
everything it uses through the Registry.

**Names** are `module.action`, in the past tense — a fact, not a command:

```
order.placed     order.cancelled   order.completed
payment.captured user.registered   invoice.issued
```

Keep them in one place per module, so that a typo fails loudly instead of silently registering a
listener that never fires:

```php
final class OrderEvents
{
    public const PLACED = 'order.placed';
    public const CANCELLED = 'order.cancelled';
    public const COMPLETED = 'order.completed';
}
```

**Payload** is positional: the Domain objects first, then whatever scalars the listener needs. There
are no event classes, no payload interfaces and no envelope — the listener's signature is the
contract, and it is the only thing a listener has to agree on.

```php
$this->emit(OrderEvents::PLACED, $order);
$this->emit(OrderEvents::CANCELLED, $cancelled, $actorId, $reason);
```

```php
BaseManager::listen(OrderEvents::CANCELLED, function (OrderDomain $order, int $actorId, string $reason): void {
    // ...
});
```

**Delivery** is synchronous and small enough to state completely:

1. synchronous, inside the emitting call's stack
2. in registration order, no priorities
3. no listeners → nothing happens; emitting is always safe
4. the listener list is snapshotted when the call starts, so subscribing from inside a listener affects the next emit, not the current one
5. a throwing listener propagates and later listeners are skipped — failures are never swallowed
6. no wildcards, no `once`, no queue, no retries, no persistence, no cross-process delivery

Point 5 has a consequence worth stating on its own, because there is no transaction manager and no
outbox here: by the time the listeners run, the write **has already been committed**. A listener that
throws therefore turns a finished write into a failed request, and the entry layer's natural reaction,
a retry, writes the row a second time. The answers live outside this package, and both are honest:

- make the use case idempotent (a natural key, or a unique index the DAO can rely on), so a retry is safe
- absorb inside the listener only what it can genuinely recover from, and let the rest propagate

Nothing here can roll that write back, and nothing pretends to: "after commit" dispatch is not
implemented (see *What this package does not do*).

**Write, re-read, emit** is the order that makes those synchronous listeners safe. Because a listener
runs immediately and reads the same DAOs, it must never see an object that was only intended:

```php
public function capture(int $paymentId, int $actorId): PaymentDomain
{
    $payment = PaymentDomain::fromArray($this->payments->getByIdOrFail($paymentId));

    if ($payment->status !== 'PENDING') {
        throw new ConflictHttpException('PAYMENT_ALREADY_CAPTURED');
    }

    $this->payments->update([
        'payment_id' => $paymentId,
        'status'     => 'CAPTURED',
        'updated_at' => time(),
        'updated_by' => $actorId,
    ]);

    // listeners read the same DAOs immediately, so hand them the stored state
    $captured = PaymentDomain::fromArray($this->payments->getByIdOrFail($paymentId));

    $this->emit(PaymentEvents::CAPTURED, $captured, $actorId);

    return $captured;
}
```

**All of this belongs in one file**, the wiring that builds the Registry and the Managers. Nothing else
in the application is allowed to assemble or subscribe:

```php
use MiGears\Mail\Mail;

// Wiring.php — the composition root. Runs once, at initialization; every entry point shares the
// result, and no entry point wires anything itself.
final class Wiring
{
    public static function buildRegistry(
        PDO $pdo,
        RedisCache $redis,
        LoggerInterface $logger,
        MailerInterface $mailer,
    ): ContainerInterface {
        $c = new Container();          // the application's container; it implements ContainerInterface

        // --- only what needs configuring
        $c->set(PDO::class, static fn () => $pdo);
        $c->set(RedisCache::class, static fn () => $redis);
        $c->set(LoggerInterface::class, static fn () => $logger);
        $c->set(MailerInterface::class, static fn () => $mailer);

        // --- the DAOs, built from concrete collaborators: no Registry goes below the Manager
        $c->set(OrderDao::class, static fn () => new OrderDao($pdo, $logger));
        $c->set(OrderItemDao::class, static fn () => new OrderItemDao($pdo, $logger));
        $c->set(InvoiceDao::class, static fn () => new InvoiceDao($pdo, $logger));
        $c->set(ShippingDao::class, static fn () => new ShippingDao($pdo, $logger, $redis));
        $c->set(UserDao::class, static fn () => new UserDao($pdo, $logger));

        // --- the Managers, each built from the Registry it will resolve from
        $c->set(OrderManager::class, static fn () => new OrderManager($c));
        $c->set(InvoiceManager::class, static fn () => new InvoiceManager($c));
        $c->set(ShippingManager::class, static fn () => new ShippingManager($c));
        $c->set(UserManager::class, static fn () => new UserManager($c));

        // --- subscribed last, once. This is the only place that knows an order also produces
        //     an invoice, schedules a shipment, sends a mail and queues a report.
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($c): void {
            $c->get(InvoiceManager::class)->issueFor($order);
        });
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($c): void {
            $c->get(ShippingManager::class)->createShipmentFor($order->id);
        });
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($c): void {
            $user = $c->get(UserManager::class)->getById($order->user_id);
            if ($user === null) {
                return;
            }
            $c->get(MailerInterface::class)->send(new Mail(
                to: [$user->email],
                subject: 'Your order has been placed',
                body: 'Order #' . $order->id,
            ));
        });
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order): void {
            BackgroundJob::script(__DIR__ . '/workers/report.php', [(string) $order->id]);   // migears/jobs
        });

        return $c;
    }
}
```

No entry point wires anything — that is what doing it here buys. What an entry point does is *use* the
result: when the container is the `MiRest` instance itself, its resources resolve from it directly;
when the application keeps a container of its own, that container's factories are handed to `MiRest` at
bootstrap, and a CLI command or a test uses the container as it is. Because the two-phase split is
real, a listener cannot behave differently in a request, a cron run and a test.

A listener may call another Manager — that is how cross-module work is done. What it must not do is
re-enter the use case that emitted the event, or write back into the same rows synchronously, because
then a decision is being made after the fact and only sometimes. Slow work belongs in `migears/jobs`,
not on the bus.

**Testing events** needs no mock: register a listener the way the wiring does, then assert what it saw.

```php
$payments = new InMemoryPaymentDao(['status' => 'PENDING']);
$manager  = new PaymentManager(new ArrayContainer([
    PaymentDao::class      => $payments,
    LoggerInterface::class => new NullLogger(),
]));

$captured = null;
BaseManager::listen(PaymentEvents::CAPTURED, function (PaymentDomain $payment) use (&$captured): void {
    $captured = $payment->status;
});

$manager->capture(paymentId: 7, actorId: 9);

$this->assertSame('CAPTURED', $captured);
```

Because the bus is process-wide and internal, a test suite has to start each test with an empty one —
`EventBus::reset()` in `setUp()`. That is the single documented exception to "you never touch the bus",
and it exists only because the tests of a whole suite share one process.

## Entry layer

The entry layer maps input, resolves one Manager, and serialises the Domain that comes back. It is thin
on purpose — it is the layer most likely to be duplicated, replaced or rewritten, so nothing worth
keeping should live there.

```php
use MiGears\Web\AbstractResource;
use MiGears\Web\Request;
use MiGears\Web\Response;

final class Index extends AbstractResource
{
    public function POST(Request $request): Response
    {
        // identity is resolved by the application's auth layer and passed in as a
        // plain scalar — the Manager never reads the session itself
        $actorId = (int) $this->resolve('auth_user_id');

        $order = $this->resolve(OrderManager::class)->place($request->body, $actorId);

        return Response::json(['order_id' => $order->id, 'status' => $order->status], 201);
    }
}
```

Note what the resource resolves: `OrderManager`, never `OrderDao` and never a Registry. It also wires
nothing — the registry it draws from was assembled, listeners included, before the request was routed.
And it does not know that an invoice, a shipment or an email follows the order; neither does the
Manager. Both of them only know that the order was placed.

## Anti-patterns

The list below is not style advice. Each entry is a specific failure this layering exists to prevent,
and they are ordered roughly by how often they turn up in code that started out clean. When you
recognise one of them, the fix column is usually the smaller change.

| Smell | Why it hurts | Fix |
|---|---|---|
| **God Manager** — one class covering registration, profile and billing | Several modules behind one change surface: every change risks all of them | Split by module, never by method |
| **One class per operation** — `OrderPlaceManager`, `OrderCancelManager`, `OrderShipManager` | The module's rules scatter across files; shared guards get copied or forgotten | One class per module, one method per use case |
| **A DAO that leaks out of its Manager** — resolved by the entry layer, or passed as a parameter | Two doors into one table: the module's rules can be walked around | Keep the DAO a private property; resolve it in the Manager |
| **The Registry below the Manager** — a DAO, a Domain or the SQL layer taking a `ContainerInterface` | Those layers become unusable without a container, and their real dependencies disappear from view | Pass concrete collaborators (pdo, logger, redis) into those constructors |
| **Registry as a junk drawer** — value objects, Domains and helpers registered in it | It stops meaning "the configured things"; every id becomes a guess | Register only what needs configuring; `new` the rest |
| **Sends mail itself** | The use case now needs SMTP to be tested, and every new channel edits the Manager | Emit an event |
| **Returns arrays or JSON** — `['code' => 0, ...]` | The layer that knows least about HTTP decides the response shape | Return Domains |
| **Reads the request** | The use case cannot run from cron, or be tested without HTTP | Pass data in |
| **Static state in business code** — `instance()`, a static container | Tests leak into each other; the object graph becomes invisible | Inject the Registry; one Manager per request. The bus is the package's single deliberate exception, and business code never holds it |
| **Two Managers kept in sync by hand** | One write is eventually forgotten | One owner writes, the other reacts to an event |
| **Event used as a command** — `emit('ship.the.order', $order)` | The emitter quietly takes the responsibility back, invisibly | Name the fact: `order.placed` |
| **Emitting before the write** | A synchronous listener reads a row that is not there yet | Write, re-read, emit |
| **One event per loop iteration** | 5,000 rows become 5,000 listener calls and 5,000 log lines | One event per batch, or none |
| **A listener that writes back into the same rows** | Re-enters a use case that already decided, and can loop | Do it in the Manager, before the emit |
| **A listener that swallows everything** — `catch (\Throwable) {}` | A broken side effect becomes invisible | Catch only what you can recover from |
| **Wiring in the web entry** — listeners subscribed in the HTTP bootstrap | The CLI command and the tests then emit into a bus nobody listens to, and one use case behaves differently per entry point | Wire once where the Managers are built, and let every entry point share it |
| **Registering listeners at runtime** — subscribing inside a Manager, a resource or a job | Side effects depend on which code path ran first, and a long-running process re-subscribes on every call | Subscribe once, at initialization |
| **Business rules in a listener** | The rule now runs after the fact, and only sometimes | Rules in the Manager, consequences in the listener |

Three of them are worth seeing in code, because in each case the wrong version is the one that looks
tidier at a glance.

The first is naming. An event name that contains a verb is a command in disguise, and two listeners
will each assume they own the action:

```php
// ✗ event as a command — two listeners will each assume they own the action
$this->emit('ship.the.order', $order);

// ✓ a fact — one listener owns each consequence
$this->emit(OrderEvents::PLACED, $order);
```

The second is ordering. Emitting first *looks* harmless until you remember that delivery is
synchronous and listeners read the database:

```php
// ✗ emit first: the listener reads an order that is not stored yet
$this->emit(OrderEvents::PLACED, $order);
$this->orders->insert($order->toArray());

// ✓
$id = $this->orders->insert($order->toArray());
$this->emit(OrderEvents::PLACED, OrderDomain::fromArray($this->orders->getByIdOrFail($id)));
```

The third is a listener that decides. It reads as harmless bookkeeping, but the rule it encodes now
runs after the use case has finished, and only when somebody happens to be listening:

```php
// ✗ a listener that writes back into rows the use case already decided about
BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($registry): void {
    $registry->get(OrderDao::class)->update(['id' => $order->id, 'status' => 'PLACED']);
});
```

## Trade-offs

Being small means deciding what *not* to build. These are the choices worth knowing about, and how to
reverse them if your project disagrees — none of them is load-bearing for the others.

| Decision | Alternative | Why | To change it |
|---|---|---|---|
| The Manager's constructor takes a PSR-11 container | List every DAO and service as a parameter | Adding a collaborator never re-edits the wiring, and the constructor stays readable; the cost is that the constructor no longer spells out what a Manager is made of — resolving into private properties puts that back at the top of the class | Pass concrete collaborators instead; nothing else changes |
| Only the Manager sees that container | Let DAOs and Domains resolve what they need | Lower layers stay usable in a script, a migration or a test with no container at all | — |
| The contract is PSR-11's (`psr/container`) | Declare our own `Registry` interface here | The web package can implement the same contract without depending on this one — and any PSR-11 container works, including one in a test. The cost is that the standard name says "container", which carries auto-wiring associations we do not honour | Declare your own interface and accept the dependency between the two packages |
| The event bus is internal and process-wide | Pass it in, or register it in the Registry | It is plumbing, not a collaborator: neither a Manager nor a wiring should have to hold it. The cost is one shared instance per process | Send events through your own mechanism instead of `emit()` |
| `BaseManager` gives only the logger and `emit()` | A fat base class with CRUD, logger and registry access | Every extra member is a decision the framework makes for you | Don't extend it; implement your own `emit()` |
| Event names are plain strings | One class per event | No files, no inheritance; the cost is that a typo is silent, hence the constants | Use class names as event names |
| Payload is positional values | A single event object | No envelope, no base class; the signature documents it | Pass one object as the only payload |
| No interface per DAO | Interface per table | One implementation is not a seam | Add the interface when a second implementation exists |

## Installation

```bash
composer require migears/manager
```

Requires: PHP 8.1+, `psr/container`, `psr/log`.

Pairs with `migears/domain` and `migears/dao`, but does not require them: the Manager contract is a
convention, not an interface. A Manager over hand-written SQL and plain objects is just as valid.

## API Reference

### The container: `Psr\Container\ContainerInterface`

Not ours — PSR-11's, from `psr/container`. The application implements it; a Manager type-hints it and
resolves from it.

| Method | Returns | Description |
|---|---|---|
| `has(string $id)` | `bool` | Whether an id is registered — for probing what is optional |
| `get(string $id)` | `mixed` (per the PSR docblock) | The object registered under the id; PSR-11 requires a `NotFoundExceptionInterface` when the id is unknown |

Two of the rules around it come from this package rather than from PSR-11: only Managers depend on it,
and it holds configured objects only. `NotFoundExceptionInterface` extends `ContainerExceptionInterface`,
which extends `Throwable`, so a container's not-found exception can also be a `RuntimeException` and be
caught either way.

### `BaseManager`

| Member | Visibility | Description |
|---|---|---|
| `__construct(ContainerInterface $registry)` | public | Resolves the logger, which is mandatory; subclasses forward with `parent::__construct($registry)` and resolve their own DAOs |
| `listen(string $event, callable $listener)` | public static | Subscribe a listener — the wiring calls this once, at initialization |
| `emit(string $event, mixed ...$payload)` | protected | Emit an event once the write has succeeded |
| `logger()` | protected | The `Psr\Log\LoggerInterface` every Manager was given; never null |

`EventBus` is internal and `final`. It is not part of the API, is never injected and never registered; the tests are the one place that builds and resets it directly.

## What this package does not do

- No container interface of its own, and no container implementation: it speaks PSR-11 (`psr/container`) so that it and the web package stay independent of each other
- No auto-wiring, no reflection, no constructor inspection — an id is asked for by name, and a missing one throws
- No queue, worker, retry or dead letter
- No deferred or "after commit" dispatch
- No wildcards, priorities, `once`, middleware or subscribers
- No event classes, payload interfaces or serialisation
- No transaction manager, no query builder, no interface per table
- No logging implementation — a PSR-3 logger arrives through the Registry, and a listener does the notifying
- No HTTP knowledge of any kind

## License

MIT

---

# migears/manager

![Version](https://img.shields.io/badge/version-2.0.0-blue)

业务层 — 拥有一个模块的业务操作、经 DAO 落库，并把每一项副效应都宣告为事件。

> **背景**：miGears 是自研 PHP 框架 **TinyGears** 的开源后继。因 *TinyGears* 这个名字在开源社区已被占用，近期更名并开源。

## 设计精神

一个代码库里，业务规则应该只有一处可以落笔的地方。在 miGears 里，这个位置就是 Manager ——
**一个业务模块一个类**：模块的表、表上的读写、以及所有会改变它们状态的操作。`OrderManager` 就是
订单表所在的地方，也是「下单、取消、完成」所在的地方。而在类内部，**每个公开方法就是一个用例**，
从第一行跑到最后一行，调用方不需要再补任何一步。

规则所操作的数据，是一个**自带校验规则的普通对象** — `public readonly` 字段，没有基类、没有 getter、
没有 mapper，也不知道自己从哪里来、要到哪里去。存储被挡在**另一道很薄的边界**后面：一张表一个 DAO，
直接写 SQL，中间不再垫一层抽象。而一切属于「副效应」的东西 — 邮件、日志、后台任务、别的模块的数据 —
都**以事件的形式宣告出去**，由监听器去完成，Manager 从此不必知道这些服务存在。

Manager 伸手可及的范围被刻意收得很窄：应用配置好的那些对象（PDO、Redis、logger、mailer，以及由它们
构建出来的 DAO）统一从**一个注册表 —— 一个 PSR-11 容器**取；其余的东西 —— Domain、值对象、小工具 ——
一律直接 `new`。Manager 之下的任何一层都见不到这个容器：DAO 收的是 PDO 和 logger，永远不是注册表。

如果你在企业架构那套文献里见过这个形状 —— 「领域驱动设计」中关于「模型、存储边界、通知三者分离」的那一部分 ——
这里就是那个子集，仅此而已。以下不再使用那套词汇，直接看代码。

| 规则 | 含义 |
|---|---|
| **一个 Manager 一个模块** | `OrderManager` 拥有订单表和订单上发生的一切；它不拥有发票、支付或账号。 |
| **一个方法一个用例** | `place()` 从校验一路跑到它发出的事件；调用方永远不用替它收尾。 |
| **Manager 是唯一那扇门** | 模块的 DAO 由它的 Manager 解析，且不外传；所有读和写都经过 Manager。 |
| **配置好的对象来自 Registry** | 构造函数收 Registry，并在构造期把需要的东西解析成私有属性。 |
| **数据进，Domain 出** | 入参是普通数组与标量，出参是 Domain 对象。 |
| **不碰外部服务** | 邮件、短信、外部 HTTP、日志、后台任务：发事件，让监听器去拥有它们。 |
| **先落库，再通知** | 先持久化、再重读、最后 `emit()`。事件陈述的是已经落库的事实。 |

两条推论值得写下来，因为下文大部分取舍都由它们解释：

- Manager 该多长就多长：简单的模块几十行，状态变更和查询多的模块几百行也正常。长度不是信号，**覆盖到第二个模块才是**。一旦它开始伸手要发票的数据，就该拆成两个类了，而分界线通常就是「它需要另一张表」的那一刻。
- Manager 不在调用之间保存状态。除了构造期解析进来的东西，它什么都不记 —— 这也是它可以每请求构建一个的原因。

## 两个阶段：装配，然后运行

本包的一切都围绕这一刀切开。装配**只在初始化时发生一次**；此后不再有任何配置、订阅或猜测。

```
Wiring —— 初始化时执行一次
    构建 Registry          pdo · redis · logger · mailer · 各个 DAO · 各个 Manager
    订阅监听器             BaseManager::listen()

运行时 —— 每请求、每命令
    Resource / Command / Task ──▶ Manager ──▶ Domain        字段与规则
                                    │     └─▶ DAO ──▶ SQL ──▶ PDO
                                    └─emit()─▶ 监听器        邮件、日志、后台任务、其它 Manager
```

运行时那一半刻意很笨：Manager 作判断、落库、宣告。它无法分辨自己是被 web 请求、cron 命令还是测试调用的，
这正是重点 —— 同一个用例不会因为调用方式不同而表现不同。

## 注册表：一个 PSR-11 容器

注册表就是「应用配置好的东西，按 id 取」。它**不是**自动装配容器：它不构建任何东西、不检查构造函数的
类型、也不做任何猜测。它只有两个方法 —— 而且这两个方法不是我们定的，是 **PSR-11**（`psr/container` 包）：

```php
interface ContainerInterface                       // Psr\Container\ContainerInterface
{
    public function has(string $id): bool;         // 探测 —— 用于可有可无的对象
    public function get(string $id);               // 取得 —— 未注册时必须抛异常
}
```

用标准接口而不是自建一个，是一个刻意的依赖决策。`psr/container` 只含接口，正是它让**本包与
`migears/web` 能说同一个契约、却互不依赖**：如果契约定义在本包里，web 包为了 type-hint 它就得依赖本包。

`get()` 取不到时**必须抛**。这本来就是 PSR-11 的规定，也正是我们要的：id 缺失属于装配错误，就该在要它的
地方炸掉，而不是过一阵子以「某个东西不是对象」的形式冒出来。在这一点上返回 `null` 的实现，既违反契约，
也丢掉了「早失败」这一保证。

**该放进去的** —— 需要配置的东西，和框架自身的规矩一致：

```
pdo · redis · logger · mailer · cache · config        连接、客户端、凭据、路径
各个 DAO                                               由上面这些构建
各个 Manager                                           由 Registry 构建
```

**不该放进去的** —— 凡 `new` 一下就行的：

```php
$order  = OrderDomain::fromArray($row);      // 值对象
$total  = new Money($cents);                 // 值对象
$errors = OrderDomain::validateArray($form); // 没什么可配置的
```

一个塞了值对象的 Registry 会不再等于「配置好的那些东西」，而变成一个每个 id 都靠猜的杂物抽屉。

### 谁可以依赖它

**只有 Manager。** 这条规则让框架其余部分在完全没有容器的情况下依然可用：

```php
final class OrderManager extends BaseManager
{
    private readonly OrderDao $orders;
    private readonly OrderItemDao $orderItems;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);                      // logger 从这里来
        $this->orders = $registry->get(OrderDao::class);      // 构造期解析一次
        $this->orderItems = $registry->get(OrderItemDao::class);
    }
}
```

```php
final class OrderDao                                    // 绝不出现 Registry
{
    use SingleTableDao;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }
}
```

在构造期解析而不是在每个方法里现取，有两个原因：这个 Manager 由什么组成，在类顶部一眼可见；
漏注册会在应用启动时就失败，而不是在请求跑到一半时失败。

在应用里，整个应用只有一个容器 —— 通常就是 `MiRest` 实例本身（它实现了该接口），也可以是任何别的
PSR-11 容器；wiring 往它里面注册，所有 Manager 从它取数。在测试里，容器就是那个「两个方法的替身」。

## 骨架

```php
use Psr\Container\ContainerInterface;
use MiGears\Manager\BaseManager;

final class OrderManager extends BaseManager
{
    private readonly OrderDao $orders;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);                       // logger 从这里来
        $this->orders = $registry->get(OrderDao::class);
    }

    public function place(array $form, int $actorId): OrderDomain
    {
        $errors = OrderDomain::validateArray($form);                 // 1. 校验
        if ($errors !== []) {
            throw new UnprocessableEntityHttpException('INVALID_ORDER');
        }

        $id = $this->orders->insert([...]);                          // 2. 落库
        $order = OrderDomain::fromArray($this->orders->getByIdOrFail($id));   // 3. 重读

        $this->emit(OrderEvents::PLACED, $order);                    // 4. 通知

        return $order;                                               // 5. 交回 Domain
    }
}
```

五步，顺序本身有意义：先校验再碰数据库；写完再重读，交回去的才是库里那个对象；通知放在最后。
上面 `insert()` 的内容省略了，具体写法在下面的 *Domain* 与 *DAO* 两节。

用一个两条目的 Registry 就能跑 —— 不需要容器、不需要 bootstrap、不需要 HTTP：

```php
$orders  = new InMemoryOrderDao();
$manager = new OrderManager(new ArrayContainer([
    OrderDao::class         => $orders,
    LoggerInterface::class  => new NullLogger(),
]));

$seen = [];
BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use (&$seen): void {
    $seen[] = $order->id;
});

$order = $manager->place(['user_id' => 1, 'title' => 'First order', 'amount' => 2500], actorId: 9);

$this->assertSame(1, $order->id);
$this->assertSame([1], $seen);
```

`BaseManager` 就是 logger 和 `emit()` 的来源，所以每个 Manager 都继承它。它不给别的东西：没有 CRUD、
没有查询助手、也没有取 Registry 的访问器 —— Manager 在自己的构造函数里用完 Registry 就忘掉它。

## Manager 的职责

一个 Manager 拥有一个业务模块：模块的表、表上的读写、以及所有会改变其状态的操作。于是每个方法都是一个
完整用例 —— 加载需要的 Domain、作出判断、经 DAO 落库、发出「发生了什么」。这份职责是刻意收窄的，
而它的大部分价值恰恰来自「不许做」的那部分：那些事一旦被允许，就会在三个层里各写一遍，行为还各不相同。

所以模块对外的表面有两类方法，而它们属于同一个类：

```php
final class OrderManager extends BaseManager
{
    private readonly OrderDao $orders;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);
        $this->orders = $registry->get(OrderDao::class);
    }

    // --- 读：模块通往自己那张表的唯一入口，也是「行变成 Domain」的地方
    public function getById(int $orderId): ?OrderDomain
    {
        $row = $this->orders->getById($orderId);

        return $row === null ? null : OrderDomain::fromArray($row);
    }

    /** @return list<OrderDomain> */
    public function listByUser(int $userId): array
    {
        return array_map(
            fn(array $row) => OrderDomain::fromArray($row),
            $this->orders->getOpenByUserId($userId),
        );
    }

    public function count(): int
    {
        return $this->orders->count();
    }

    // --- 业务操作：一个方法，一个完整用例
    public function complete(int $orderId, int $actorId): OrderDomain
    {
        $order = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

        if ($order->status !== 'PLACED') {
            throw new ConflictHttpException('ORDER_NOT_COMPLETABLE');
        }

        $this->orders->update([
            'id'         => $orderId,
            'status'     => 'COMPLETED',
            'updated_at' => time(),
            'updated_by' => $actorId,
        ]);

        $completed = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

        $this->emit(OrderEvents::COMPLETED, $completed, $actorId);

        return $completed;
    }
}
```

转发式的读不是白写的：行变 Domain 的地方在这里，模块的缓存选择在这里，整个应用通往那张表的唯一一扇门也在这里。
它不能是**第二**扇门 —— 如果入口层还能直接够到 `OrderDao`，这道门面就不再划算，两边的约定迟早会漂开。

| 它绝不做 | 应该怎么做 |
|---|---|
| 读 `$_POST` / `$_FILES` / header / session | 由入口层完成映射，只传数组与标量进来。 |
| 构造响应、选状态码、拼 JSON | 返回 Domain，由入口层序列化。 |
| 写 SQL、为了省事持有 `PDO` | 查询放 DAO，DAO 从 Registry 取。 |
| 发邮件 / 短信 / HTTP 调用、写日志 | 发事件；`logger()` 是用来诊断的，不是用来通知的。 |
| 伸手去用别的模块的 DAO | 调用那个模块的 Manager，从 Registry 取。 |
| 在自己代码里维护单例、静态注册表、服务定位器 | 注入 Registry，在构造期解析；本包自己的总线是它唯一的进程级状态，而且是内部件 |
| 在调用之间记住请求状态 | 当成参数传进来。 |
| 让别的任何地方持有、解析或接收它的 DAO | 让 DAO 保持为私有属性；Manager 才是模块通往自己那几张表的唯一一扇门。 |

## 四个习惯，以及它们的反例

**入参：在外面映射，不在里面。** Manager 的参数就是它的接口，而接口应该是数据。`$_POST` 和 session 属于入口层，
那也应该是唯一知道状态码的地方。

```php
// ✗ Manager 知道了传输层
public function place(): OrderDomain
{
    $userId = (int) $_POST['user_id'];
}

// ✓ Manager 只接收数据
public function place(array $form, int $actorId): OrderDomain
```

**查询：问 DAO，不问数据库。** 一旦 Manager 手里有了 `PDO`，早晚会写出一条语句，然后同一张表的 SQL 就散落在两个文件里。
每条查询只该有一个家。

```php
// ✗
$rows = $this->pdo->query("SELECT * FROM orders WHERE user_id = {$userId}");

// ✓
$rows = $this->orders->getOpenByUserId($userId);
```

**副效应：发出它，不要亲自做。** 自己发邮件的 Manager，测试时就得连上 SMTP，而且每加一个通知渠道都要改它。
只发事件的 Manager，只知道「发生了一件事」。

```php
// ✗ Manager 知道了邮件
$this->mailer->send(new Mail(to: [$user->email], subject: 'Your order has been placed'));

// ✓ Manager 只知道「订单已创建」
$this->emit(OrderEvents::PLACED, $order);
```

**出参：Domain 对象，不是数组。** 调用方拿到的是能读的字段和能依赖的类型，序列化只发生在真正决定响应形态的地方。

```php
// ✗ 每个调用方都得重新组装一遍，还要猜键名
return $order->toArray();

// ✓
return $order;
```

## Domain

Domain 就是「一条业务记录在代码里的样子」：字段，外加判断这些字段是否可接受的规则。
两种转换来自 `migears/domain`：

```php
$order = OrderDomain::fromArray($this->orders->getByIdOrFail($id));   // 行     → Domain
$row   = $order->toArray();                                            // Domain → 行
```

规则挂在对象上而不是 Manager 里，因此两个碰同一条记录的用例，不可能对「什么算合法」有不同意见。
Manager 只决定拿到校验结果之后怎么办 —— 这里是拒绝请求，抛一个入口层已经会渲染的异常：

```php
$errors = OrderDomain::validateArray($form);
if ($errors !== []) {
    throw new UnprocessableEntityHttpException('INVALID_ORDER');
}

// $errors 是结构化数据，不是一句话，所以可翻译、也可原样返回：
// ['title' => ['rule' => 'maxLength', 'params' => ['maxLength' => 100]]]
```

一个具体的 Domain，规则就写在字段旁边：

```php
use MiGears\Domain\DataAccess;
use MiGears\Domain\Validatable;

final class OrderDomain
{
    use DataAccess;
    use Validatable;

    public function __construct(
        public readonly int $id,
        public readonly int $user_id,
        public readonly string $title,
        public readonly int $amount,
        public readonly string $status,
        public readonly int $created_at,
    ) {}

    protected static function validationRules(): array
    {
        return [
            'user_id' => ['required' => true, 'integer' => true, 'min' => 1],
            'title'   => ['required' => true, 'maxLength' => 100],
            'amount'  => ['required' => true, 'integer' => true, 'min' => 1],
        ];
    }
}
```

Domain 不该自己去取的关联数据，由 Manager 把 loader 注入进去：访问器读起来自然，
依赖知识留在真正拥有它的那一层（见 `migears/domain` → *关联数据*）。这里没有生命周期钩子，
也没有任何东西会替你调用 `boot()`：wiring 在对外提供服务之前把每个 Manager 建一次，
所以构造函数就是它该在的地方。

```php
final class OrderManager extends BaseManager
{
    private readonly OrderItemDao $orderItems;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);
        $this->orderItems = $registry->get(OrderItemDao::class);

        OrderDomain::setItemLoader(
            fn(OrderDomain $order) => $this->orderItems->getByOrderId($order->id),
        );
    }
}
```

有一个坑值得点名，因为它一直静默、直到撞上数据库为止：绝不要把协作者挂在 Domain 上。
`toArray()` 会返回**每一个**属性，包括 private 的，而那个数组正是 DAO 交给 SQL 的东西。

```php
// ✗
class OrderDomain { public function __construct(public readonly int $id, private ?OrderDao $dao) {} }

// toArray() → ['id' => 7, 'dao' => OrderDao]
// SQL        → ERROR: table orders has no column named dao
```

## DAO

DAO 只负责一张表的 SQL：收下数据行与值、交出数据行，既不知道也不关心是哪个用例在问。
它的构造函数只收**具体的协作者** —— PDO、logger、Redis 客户端 —— 永远不收 Registry。
这正是 DAO 在裸脚本、迁移脚本或完全没装配的测试里依然能用的原因。

一个 Manager 只注入**自己这个模块**的表所对应的 DAO —— 订单和它的商品行永远一起动，所以两个都进来；
发票的 DAO 永远不会出现在这里。一个用例如果真的需要别的模块的数据，就解析那个模块的 **Manager**，
绝不用它的 DAO —— 让它去写自己的行。

```php
public function __construct(ContainerInterface $registry)
{
    parent::__construct($registry);
    $this->orders     = $registry->get(OrderDao::class);
    $this->orderItems = $registry->get(OrderItemDao::class);
}
```

一个具体的 DAO。普通 CRUD 由 trait 覆盖；之外的查询用纯 SQL 写在需要它的方法里，也正好就在那里能被读到：

```php
use PDO;
use Psr\Log\LoggerInterface;
use MiGears\Dao\SingleTableDao;

final class OrderDao
{
    use SingleTableDao;

    protected string $table = 'orders';
    protected string $idColumn = 'id';

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->initDao($pdo, $logger);
    }

    /** @return list<array<string, mixed>> */
    public function getOpenByUserId(int $userId): array
    {
        return $this->getSqlBuilder()
            ->select()
            ->from($this->table)
            ->where('`user_id` = :user_id AND `status` = :status', ['user_id' => $userId, 'status' => 'PLACED'])
            ->execute();
    }
}
```

把多个 DAO 协调起来是 Manager 的活，下面这段就是 `place()` 的完整实现 —— 也就是骨架里那句
`insert([...])` 展开后的样子。先读一遍判断部分（字段合法吗？有商品行吗？总额多少？），
再读一遍落库部分（一条订单行，然后每个商品一条）；两者从头到尾没有混在一起：

```php
public function place(array $form, int $actorId): OrderDomain
{
    $errors = OrderDomain::validateArray($form);
    if ($errors !== []) {
        throw new UnprocessableEntityHttpException('INVALID_ORDER');
    }

    $items = $form['items'] ?? [];
    if ($items === []) {
        throw new ConflictHttpException('ORDER_WITHOUT_ITEMS');
    }

    $total = array_sum(array_map(fn(array $i) => (int) $i['price'] * (int) $i['quantity'], $items));

    $orderId = $this->orders->insert([
        'user_id'    => (int) $form['user_id'],
        'title'      => trim((string) $form['title']),
        'amount'     => $total,
        'status'     => 'PLACED',
        'created_at' => time(),
        'created_by' => $actorId,
    ]);

    foreach ($items as $item) {
        $this->orderItems->insert([
            'order_id' => $orderId,
            'sku'      => $item['sku'],
            'price'    => (int) $item['price'],
            'quantity' => (int) $item['quantity'],
        ]);
    }

    $order = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

    $this->emit(OrderEvents::PLACED, $order);

    return $order;
}
```

**事务。** 这里没有隐藏的事务管理器需要推理，所以规则一张表就够。常见情况下 —— 单条语句，或者总是成对变动的两张表 ——
这个问题根本不会传到 Manager 这里。

| 情形 | 事务归属 |
|---|---|
| 单条语句 | 无人在管 — 它本身就是原子的。 |
| 永远一起动的两张表 | DAO：它持有连接，就由它 `beginTransaction()` / `commit()` / `rollBack()`。 |
| 一个用例里几个彼此独立的 DAO | Manager，显式接收 `PDO`。这是看得见、刻意的代价 — 先问问这次写入是不是本该属于某一个 DAO。 |

如果一个用例需要在同一个事务里动用三个 DAO，通常说明其中一个少了一个方法，而不是 Manager 需要去持有连接。

## 事件

任何「系统里已经成立、并且可能有人关心」的事实，都是一个事件。Manager 一次调用宣告它，然后就不管了：

```php
$this->emit(OrderEvents::PLACED, $order);
```

谁在听，完全在别处决定，而且在初始化时就决定好：

```php
BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($registry): void {
    $registry->get(InvoiceManager::class)->issueFor($order);
});
```

这两次调用背后的总线是**本包的内部件**。你不会在签名里写它、不会注册它、不会把它传给构造函数、
也不会把它放进 Registry。`BaseManager` 就是全部对外面：`listen()` 给装配用，`emit()` 给 Manager 用。

这条总线是本包唯一的进程级状态，这个例外是刻意的：一个进程一条总线，装配时注册的监听器才能被每个
Manager 看见，而任何业务代码都不会持有它的引用。除它之外本包没有任何静态状态 —— Manager 用到的东西
依然一律从 Registry 取。

**命名**是 `模块.动作`，过去式 — 是事实，不是命令：

```
order.placed     order.cancelled   order.completed
payment.captured user.registered   invoice.issued
```

每个模块把事件名收在一处，这样拼错时是响亮的报错，而不是静默注册了一个永远不会被触发的监听器：

```php
final class OrderEvents
{
    public const PLACED = 'order.placed';
    public const CANCELLED = 'order.cancelled';
    public const COMPLETED = 'order.completed';
}
```

**载荷**是位置参数：Domain 在前，监听器需要的标量在后。没有事件类、没有载荷接口、没有信封 ——
监听器的签名就是契约，也是监听器唯一需要同意的东西。

```php
$this->emit(OrderEvents::PLACED, $order);
$this->emit(OrderEvents::CANCELLED, $cancelled, $actorId, $reason);
```

```php
BaseManager::listen(OrderEvents::CANCELLED, function (OrderDomain $order, int $actorId, string $reason): void {
    // ...
});
```

**投递行为**是同步的，短到可以完整讲清楚：

1. 同步，在发出调用的同一调用栈内
2. 按注册顺序，没有优先级
3. 没有监听器就什么都不发生；发事件永远安全
4. 调用开始时对监听器列表做快照，所以在监听器内部订阅，影响的是下一次 emit，不是当前这次
5. 监听器抛异常会向上传播，其后的监听器被跳过 — 失败永远不会被吞掉
6. 没有通配符、没有 `once`、没有队列、没有重试、没有持久化、没有跨进程投递

第 5 条有一个后果值得单独说，因为这里既没有事务管理器也没有 outbox：监听器运行时，写入**已经提交**。
于是抛异常的监听器会把一次已经完成的写入变成一次失败的请求，而入口层最自然的反应 —— 重试 —— 会把这一行
写第二遍。答案都在本包之外，而且都诚实：

- 让用例幂等（自然键，或一个 DAO 可以依赖的唯一索引），重试才是安全的
- 监听器只消化它确实能恢复的失败，其余一律上抛

本包没有办法回滚那次写入，也不假装有：「提交后」派发没有实现（见 *本包不做的事*）。

**落库、重读、发事件**，这个顺序正是让同步监听器安全的原因。监听器会立刻执行、并且读同一批 DAO，
所以它绝不能看到一个「只是打算写入」的对象：

```php
public function capture(int $paymentId, int $actorId): PaymentDomain
{
    $payment = PaymentDomain::fromArray($this->payments->getByIdOrFail($paymentId));

    if ($payment->status !== 'PENDING') {
        throw new ConflictHttpException('PAYMENT_ALREADY_CAPTURED');
    }

    $this->payments->update([
        'payment_id' => $paymentId,
        'status'     => 'CAPTURED',
        'updated_at' => time(),
        'updated_by' => $actorId,
    ]);

    // 监听器会立刻读同一批 DAO，所以交给它们的是已存储的状态
    $captured = PaymentDomain::fromArray($this->payments->getByIdOrFail($paymentId));

    $this->emit(PaymentEvents::CAPTURED, $captured, $actorId);

    return $captured;
}
```

**以上全部归一个文件管** —— 那份构建 Registry 与 Manager 的 wiring。应用里其它任何地方都不允许装配或订阅：

```php
use MiGears\Mail\Mail;

// Wiring.php —— 组装点。初始化时执行一次；所有入口共享它的产物，没有哪个入口自己做装配。
final class Wiring
{
    public static function buildRegistry(
        PDO $pdo,
        RedisCache $redis,
        LoggerInterface $logger,
        MailerInterface $mailer,
    ): ContainerInterface {
        $c = new Container();          // 应用自己的容器；它实现了 ContainerInterface

        // --- 只放需要配置的东西
        $c->set(PDO::class, static fn () => $pdo);
        $c->set(RedisCache::class, static fn () => $redis);
        $c->set(LoggerInterface::class, static fn () => $logger);
        $c->set(MailerInterface::class, static fn () => $mailer);

        // --- DAO：只收具体协作者，Registry 不下沉到 Manager 以下
        $c->set(OrderDao::class, static fn () => new OrderDao($pdo, $logger));
        $c->set(OrderItemDao::class, static fn () => new OrderItemDao($pdo, $logger));
        $c->set(InvoiceDao::class, static fn () => new InvoiceDao($pdo, $logger));
        $c->set(ShippingDao::class, static fn () => new ShippingDao($pdo, $logger, $redis));
        $c->set(UserDao::class, static fn () => new UserDao($pdo, $logger));

        // --- Manager：各自从它将要取数的那个 Registry 构建
        $c->set(OrderManager::class, static fn () => new OrderManager($c));
        $c->set(InvoiceManager::class, static fn () => new InvoiceManager($c));
        $c->set(ShippingManager::class, static fn () => new ShippingManager($c));
        $c->set(UserManager::class, static fn () => new UserManager($c));

        // --- 最后统一订阅，只此一次。这里是唯一知道「下单还会产生发票、安排发货、发邮件、
        //     排一个报表任务」的地方。
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($c): void {
            $c->get(InvoiceManager::class)->issueFor($order);
        });
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($c): void {
            $c->get(ShippingManager::class)->createShipmentFor($order->id);
        });
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($c): void {
            $user = $c->get(UserManager::class)->getById($order->user_id);
            if ($user === null) {
                return;
            }
            $c->get(MailerInterface::class)->send(new Mail(
                to: [$user->email],
                subject: 'Your order has been placed',
                body: 'Order #' . $order->id,
            ));
        });
        BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order): void {
            BackgroundJob::script(__DIR__ . '/workers/report.php', [(string) $order->id]);   // migears/jobs
        });

        return $c;
    }
}
```

入口点里不做任何装配 —— 装配放在这里，正是为了这一点。入口点做的事只是**使用**产物：当容器就是
`MiRest` 实例本身时，它的资源直接从它取数；当应用保留了自己的容器时，在 bootstrap 阶段把它的工厂
转交给 `MiRest`，而 CLI 命令或测试则直接使用那个容器。因为「两个阶段」是真的，同一个监听器在请求、
cron 和测试里不可能表现不同。

监听器可以调用另一个 Manager —— 跨模块的动作就是这么做的。它不能做的是：重新进入发出该事件的用例，
或者同步写回同一批数据行；那等于把判断挪到了事后，而且只在「恰好有人在听」的时候才发生。
慢活交给 `migears/jobs`，不要压到总线上。

**测试事件**不需要 mock：像 wiring 那样注册一个监听器，然后断言它看到了什么。

```php
$payments = new InMemoryPaymentDao(['status' => 'PENDING']);
$manager  = new PaymentManager(new ArrayContainer([
    PaymentDao::class      => $payments,
    LoggerInterface::class => new NullLogger(),
]));

$captured = null;
BaseManager::listen(PaymentEvents::CAPTURED, function (PaymentDomain $payment) use (&$captured): void {
    $captured = $payment->status;
});

$manager->capture(paymentId: 7, actorId: 9);

$this->assertSame('CAPTURED', $captured);
```

由于总线是进程级、且属于内部件，测试套件必须让每个用例从一条空总线开始 —— 在 `setUp()` 里调用
`EventBus::reset()`。这是「永远不要碰总线」这条规矩唯一一处成文的例外，而它存在的原因仅仅是
一整个测试套件共享同一个进程。

## 入口层

入口层做三件事：映射入参、解析一个 Manager、把交回来的 Domain 序列化。它刻意很薄 ——
这是最容易被复制、替换、重写的一层，所以不该有任何值得留存的东西住在里面。

```php
use MiGears\Web\AbstractResource;
use MiGears\Web\Request;
use MiGears\Web\Response;

final class Index extends AbstractResource
{
    public function POST(Request $request): Response
    {
        // 身份由应用的鉴权层解析，并以普通标量传入 —— Manager 从不自己读会话
        $actorId = (int) $this->resolve('auth_user_id');

        $order = $this->resolve(OrderManager::class)->place($request->body, $actorId);

        return Response::json(['order_id' => $order->id, 'status' => $order->status], 201);
    }
}
```

注意这个资源解析到的是 `OrderManager` —— 从来不是 `OrderDao`，也从来不是 Registry。它也不做任何装配：
它取服务的那个 Registry，在请求被路由之前就已经装好了 —— Manager 和监听器都一样。而且这个资源并不知道
订单之后还有发票、发货和邮件；Manager 同样不知道。它们都只知道「订单已创建」。

## 常见反模式

下面这张表不是风格建议。每一条都是这套分层刻意要防住的具体故障，大致按「在本来写得挺干净的代码里出现的频率」排序。
认出其中一条时，「怎么改」那一列通常就是更小的那次改动。

| 症状 | 为什么痛 | 怎么改 |
|---|---|---|
| **上帝 Manager** — 一个类横跨注册、资料、计费 | 一个改动面上叠了好几个模块，改一处可能波及全部 | 按模块拆，绝不按方法拆 |
| **一个操作一个类** — `OrderPlaceManager`、`OrderCancelManager`、`OrderShipManager` | 模块的规则散落各处，共用的守卫被复制或被漏掉 | 一个模块一个类，一个用例一个方法 |
| **DAO 从 Manager 里漏出去** — 被入口层解析，或当参数传出去 | 一张表两扇门：模块的规则可以被绕过 | 让 DAO 保持为私有属性，在 Manager 里解析它 |
| **注册表下沉到 Manager 以下** — DAO、Domain 或 SQL 层收 `ContainerInterface` | 这些层脱离容器就不能用，它们真正的依赖也从视野里消失了 | 那些构造函数只传具体协作者（pdo、logger、redis） |
| **Registry 当杂物抽屉** — 值对象、Domain、小工具都注册进去 | 它不再等于「配置好的那些东西」，每个 id 都靠猜 | 只注册需要配置的；其余 `new` 出来 |
| **自己发邮件** | 用例的测试从此需要 SMTP，每加一个渠道都要改 Manager | 发事件 |
| **返回数组或 JSON** — `['code' => 0, ...]` | 最不了解 HTTP 的那一层，反而决定了响应形态 | 返回 Domain |
| **读 Request** | 用例无法从 cron 跑，也无法脱离 HTTP 测试 | 把数据传进来 |
| **业务代码里的静态状态** — `instance()`、静态容器 | 测试相互污染；对象图变得不可见 | 注入 Registry；每请求一个 Manager。总线是本包唯一刻意的例外，而且业务代码永远不持有它 |
| **靠人工同步的两个 Manager** | 迟早漏掉一次写入 | 一方负责写，另一方对事件作出反应 |
| **把事件当命令** — `emit('ship.the.order', $order)` | 发出方悄悄把职责又拿了回来，而且不可见 | 给事实命名：`order.placed` |
| **先发事件，后落库** | 同步监听器读到的是一行还不存在的数据 | 落库、重读、再发事件 |
| **循环里逐条发事件** | 5000 行变成 5000 次监听调用、5000 行日志 | 批量只发一个事件，或干脆不发 |
| **监听器写回同一批数据行** | 重新进入一个已经完成判断的用例，还可能成环 | 放回 Manager 里，在 emit 之前做 |
| **什么都吞的监听器** — `catch (\Throwable) {}` | 坏掉的副效应变得不可见 | 只捕获你确实能恢复的失败 |
| **在 web 入口做装配** — 订阅写在 HTTP 的 bootstrap 里 | CLI 命令和测试会把事件发到一条没人在听的总线上，同一个用例换个入口行为就不一样 | 在构建 Manager 的那一层装配一次，所有入口共用 |
| **运行时注册监听器** — 在 Manager、资源或任务里订阅 | 副效应取决于哪条代码路径先跑；长驻进程还会每次调用重复订阅 | 只在初始化时订阅一次 |
| **把业务规则放进监听器** | 规则变成事后运行，而且只在部分情况下运行 | 规则留在 Manager，监听器只管后果 |

其中三条值得看代码，因为这三处的「错版本」乍看之下反而更整洁。

第一条是命名。事件名里带动词，就是伪装成事件的命令，于是多个监听器会各自以为这件事归自己管：

```php
// ✗ 事件当命令 — 多个监听器会各自以为这件事归自己管
$this->emit('ship.the.order', $order);

// ✓ 事实 — 每个后果都只有唯一的监听器负责
$this->emit(OrderEvents::PLACED, $order);
```

第二条是顺序。先发事件*看着*无害，直到你想起投递是同步的、监听器会去读数据库：

```php
// ✗ 先发事件：监听器读到的是一笔还没落库的订单
$this->emit(OrderEvents::PLACED, $order);
$this->orders->insert($order->toArray());

// ✓
$id = $this->orders->insert($order->toArray());
$this->emit(OrderEvents::PLACED, OrderDomain::fromArray($this->orders->getByIdOrFail($id)));
```

第三条是「会作判断的监听器」。它读起来像无害的记账，但它所编码的那条规则从此运行在用例结束之后，
而且只在恰好有人在监听时才运行：

```php
// ✗ 监听器写回用例已经决定过的那些行
BaseManager::listen(OrderEvents::PLACED, function (OrderDomain $order) use ($registry): void {
    $registry->get(OrderDao::class)->update(['id' => $order->id, 'status' => 'PLACED']);
});
```

## 刻意的取舍

小，就意味着要决定「不做什么」。下面这些是值得知道的几个选择，以及如果项目不认同该怎么改回去 ——
它们之间没有谁依赖谁。

| 决定 | 另一种做法 | 为什么这样选 | 想改怎么改 |
|---|---|---|---|
| Manager 构造函数收一个 PSR-11 容器 | 把所有 DAO 与服务逐个列为参数 | 加一个协作者不必再改装配，构造函数也保持可读；代价是签名不再说明 Manager 由什么组成 —— 在构造期解析成私有属性，把这点补回类顶部 | 改成传具体协作者，其余不变 |
| 只有 Manager 见得到这个容器 | 让 DAO、Domain 自己解析需要的东西 | 下层在裸脚本、迁移脚本、无容器的测试里都还能用 | — |
| 契约用 PSR-11（`psr/container`） | 在本包里自建 `Registry` 接口 | web 包可以实现同一个契约、却不依赖本包 —— 而且任何 PSR-11 容器都能用，测试里那个也行。代价是标准名字叫「container」，自带我们并不遵循的自动装配联想 | 自建接口，并接受两个包之间的依赖 |
| 事件总线是内部件、进程级 | 传进来，或者注册进 Registry | 它是管道，不是协作者：Manager 和 wiring 都不该持有它。代价是每进程一个共享实例 | 不用 `emit()`，改用你自己的机制发事件 |
| `BaseManager` 只给 logger 与 `emit()` | 塞进 CRUD、logger 和 Registry 访问器的胖基类 | 每多一个成员，就是框架替你多做一次决定 | 不继承它，自己实现 `emit()` |
| 事件名是普通字符串 | 一个事件一个类 | 零文件、零继承；代价是拼错静默，所以推荐常量 | 改用类名做事件名 |
| 载荷是位置参数 | 单个事件对象 | 没有信封、没有基类；监听器签名即文档 | 只传一个对象作为唯一载荷 |
| 不写 DAO 接口 | 一张表一个接口 | 单实现不是接缝 | 出现第二种实现时再加 |

## 安装

```bash
composer require migears/manager
```

要求：PHP 8.1+，`psr/container`，`psr/log`。

与 `migears/domain`、`migears/dao` 搭配使用，但不强依赖它们：Manager 契约是约定，不是接口。
Manager 建在手写 SQL 与普通对象之上，同样成立。

## API 参考

### 容器：`Psr\Container\ContainerInterface`

不是本包的，是 PSR-11 的（`psr/container`）。由应用实现；Manager 通过它取对象。

| 方法 | 返回值 | 说明 |
|---|---|---|
| `has(string $id)` | `bool` | 该 id 是否已注册 — 用于探测可有可无的对象 |
| `get(string $id)` | `mixed`（按 PSR 文档块） | 取回该 id 对应的对象；id 未知时 PSR-11 要求抛 `NotFoundExceptionInterface` |

围绕它的规则里有两条来自本包、而非 PSR-11：只有 Manager 依赖它，且它只放需要配置的对象。
`NotFoundExceptionInterface` 继承 `ContainerExceptionInterface`、后者继承 `Throwable`，所以容器的
「找不到」异常可以同时是 `RuntimeException`，两种捕获方式都成立。

### `BaseManager`

| 成员 | 可见性 | 说明 |
|---|---|---|
| `__construct(ContainerInterface $registry)` | public | 解析必需的 logger；子类用 `parent::__construct($registry)` 转发，并解析自己的 DAO |
| `listen(string $event, callable $listener)` | public static | 订阅监听器 —— 由 wiring 在初始化时调用一次 |
| `emit(string $event, mixed ...$payload)` | protected | 在写入成功之后发出事件 |
| `logger()` | protected | 每个 Manager 都拿到的 `Psr\Log\LoggerInterface`，永不为 null |

`EventBus` 是内部件，而且是 `final`：不属于 API，永远不被注入，也永远不被注册；测试是唯一会直接构建并重置它的地方。

## 本包不做的事

- 不自建容器接口，也不提供容器实现：它说 PSR-11（`psr/container`），从而与 web 包互不依赖
- 没有自动装配、没有反射、不检查构造函数 —— id 按名字要，缺了就抛
- 没有队列、worker、重试、死信
- 没有延迟派发，也没有「提交后」派发
- 没有通配符、优先级、`once`、中间件、事件订阅者
- 没有事件类、载荷接口、序列化
- 没有事务管理器、没有查询构造器、没有一张表一个接口
- 不做日志实现 —— PSR-3 logger 由 Registry 提供，通知由监听器负责
- 不含任何 HTTP 知识

## 许可证

MIT
