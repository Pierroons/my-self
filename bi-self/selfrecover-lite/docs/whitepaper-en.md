# SelfRecover-Lite — Whitepaper v1.0

**Hardening email recovery, for the sites that keep it**

*The link and the device, never one without the other.*

*Edition of 11 November 2026 — v1.0 — describes the design of SelfRecover-Lite. No implementation is published at this date.*

---

## Context

SelfRecover removes email from account recovery: the user keeps a recovery word in mind, and the service no longer has a third-party mailbox to trust. That choice suits a forum, a community, a technical audience. It does not suit every site. An online shop that asked each customer to remember one more secret would lose some of them, and many sites will keep email whatever they are offered.

SelfRecover-Lite is for those sites. It keeps the usual path — "forgot password", a link received by email — and hardens it on the server side, asking nothing to be remembered by default. The price is a path that is sometimes longer: when the usual proof is missing, recovery goes through an optional word or through a human.

A "Lite" mode of SelfRecover existed as a demonstration in v0.1.1; it was removed on 18 August 2026. SelfRecover-Lite is a new design, which takes neither its code nor its mechanism.

This document describes the mechanism before any implementation: its pieces, its rules, the variants set aside and why, the threat model, the limits, and what the design borrows from existing work. Appendix A restates it step by step.

---

## Summary

A SelfRecover-Lite recovery requires two proofs: **the link** received at the account's address, which proves one reads the mailbox of that address ("the mailbox" from here on), and **a witness**, that is, a browser the holder has registered on this account and whose registration they confirmed through the mailbox. The witness is a random 32-byte secret, set by the server in a cookie, of which the server keeps only the hash. The link opened on any registered device of the account, or the code from the same email typed on one of them, resets the password.

Without a registered device, two substitutes exist, always together with the link: an optional **word**, derived in the browser as in SelfRecover, or a human **arbitrator** (the site's customer service), after a delay during which the holder is notified and can object. Never the link alone; never the witness alone.

After any recovery, a **return window**, 14 days by default, lets the dispossessed holder come back through a device registered before the recovery. The library takes charge of password changes, address changes and session closing; a conformance bench and a detector report what bypasses it (§10).

---

## 1. The problem

Email reset rests on a single proof: whoever reads the mailbox gets the account. That proof leaks in several ways.

- **The link read by someone else**: a forwarded email, a mail scanner that follows links, a link intercepted or left in a history.
- **The mailbox taken**: a stolen or reused mail password, a webmail session stolen by spyware, an address abandoned then reassigned by its provider.
- **The third party who triggers**: anyone can request a link for someone else's address, flood their mailbox, or exhaust the site's sending quota.
- **The reused password**: the same leak sometimes opens both the mailbox and the site.

Large account providers add signals (known device, location, history) and human review. A small site has neither those signals nor that review: it has an accounts table, a rented server and an email-sending service.

SelfRecover-Lite looks for what such a site can add without a daemon, without a third-party service, and without asking the customer, by default, to remember anything.

---

## 2. The principle

**Two keys, never one without the other.**

| | what it proves | why it is not enough alone |
|---|---|---|
| **the link** | that one is reading the mailbox of the account's address now | the mailbox leaks (§1) |
| **the witness** | that one holds a browser the holder registered | a device gets stolen, a cookie gets copied |

When the witness is missing — new device, cookies cleared, private browsing —, it is replaced, in this order:

1. by the **word**, optional, which the customer chose in advance (§7.2);
2. by the **arbitrator**, a human from customer service, after a delay during which the registered devices are notified (§7.3).

The link is never replaced for resetting a password: every recovery starts with the proof of the mailbox. Only changing an address whose mailbox is lost happens without it, through the arbitrator (§10). No proof silently falls back on another, and the link alone never registers a browser.

---

## 3. The frame

- **A choice made before installation, once.** An installation of SelfRecover chooses at the start between three forms: `tor-onion` (SelfRecover, behind a hidden service), `clearweb` · Standard (SelfRecover, without email) and `clearweb` · Lite (SelfRecover-Lite, with email). Each form carries its own recovery factors and its own routes, and refuses those of the others: nothing is reconfigured afterwards to move from one to another. An account therefore belongs to only one of the two libraries. SelfRecover remains the answer for whoever accepts removing email; Lite changes nothing in its doctrine. The durations the site can set (the delay before the arbitrator's approval, the return window) are also set before installation, between a minimum and a maximum the library imposes; once the installation has started, nothing is set any more.
- **Never under Tor.** The library refuses to be built with the `TOR_ONION` profile: an account tied to an email address is the opposite of what one looks for behind a hidden service, and the origin address of a request is unknown there.
- **The origin address.** The library refuses a loopback, private or reserved address as a client address. Behind a proxy or a CDN, the host declares its trusted proxies and the library derives the client address from them; an inconsistent configuration is refused loudly. **No guarantee in this document rests on the per-address brake alone**: a whole IPv6 range fits in a single attacker's hands.
- **A PHP library, with no daemon and no scheduled task.** It does not send email: it returns the content and its priority, and the host sends it. It does not hash the site's password: the host keeps that. Changing the password, changing the address and closing sessions, on the other hand, must go through it (§10).
- **The bricks shared with SelfRecover**: Argon2id hashing and its single profile (`Crypto/Hashing`), the browser deriver (`client/sr-derive.js`), the deployment profiles, the languages, the pepper (§9.1), and the core of level 3 (`Escalade`), extracted in SelfRecover to serve both. The dependency goes one way only: Lite depends on SelfRecover, never the reverse.

---

## 4. The witness

### 4.1 What it is

A secret of **32 bytes drawn at random** by the server, stored in a cookie the server sets itself.

| attribute | value | reason |
|---|---|---|
| name prefix | `__Secure-` | the browser accepts the cookie only over an encrypted connection |
| domain | none | the cookie stays attached to the exact host; a subdomain does not receive it |
| path | the prefix of Lite's routes (request, recovery, registration, renewal, removal, objection, return, address change) | the cookie does not travel with the site's other requests: a compromised server sees the secret only of the clients that go through these routes during the compromise |
| `HttpOnly` | yes | no script on the page reads it |
| `SameSite` | `Lax` | a request launched by another site does not carry it, outside top-level navigation |
| `Max-Age` | 400 days | the cap of the RFC 6265bis draft (§5.5), applied by Chrome since version 104 and by Firefox since version 132; renewal pushes it back |
| response that sets it | served from the site's address range | a browser that cuts cookies set from a third-party address to 7 days does not shorten it |

**Why a cookie set by the server**, and not script-written storage or a WebCrypto key: some browsers erase, after seven days of use without interaction with the site, what a script wrote (local storage, IndexedDB, and therefore a key living there). A customer who orders once a month would lose their witness between two visits.

**One cookie per account**: its name carries a hash of the account. Two accounts open in the same browser each have their witness, and registering one never removes the other's.

**What the server keeps**, for each witness: the hash of the secret, the registration date, the confirmation date, the date of the last visit, and the bounded history of the hashes this witness has carried.

### 4.2 What it does not contain

The cookie contains **only the secret**: no device identifier, no browser fingerprint, no date, no account identifier in clear. Dates live on the server side. A stolen cookie reveals nothing; a forged cookie is just an unknown secret, which does nothing.

The design has **no account risk state** that would make login depend on the witness, and **no "unauthorized" cookie**. The witness never serves ordinary login, which remains the site's own: it serves recovery only. A risk state that an outside event can flip is a lever a third party ends up pulling against the holder. The rule common to MySelf modules applies: a received signal may harden a path, never punish the holder.

### 4.3 Registration

1. The customer logs in by password from a browser that carries no witness of this account. The host asks them: "register this device? yes / no".
2. **No**: the answer is remembered in this browser (a preference, not a secret), and the question does not come back at every visit. A public computer does not get registered.
3. **Yes**: the server sets a **provisional** witness. A provisional witness opens nothing and receives no notice. An email confirming a new device goes to the account's address.
4. **The confirmation is valid only in the browser that carries the provisional witness.** The email's link, opened in that browser, confirms the witness. Opened elsewhere — the in-app browser of a mail application, another device —, it confirms nothing: it says so, and offers to type, **on the device to be registered**, the code carried by the same email.
5. **Each confirmation is announced** to the account's address and to all other confirmed witnesses.

**Why provisional**: a stolen password is enough to log in. Without confirmation through the mailbox, it would also be enough to set a witness that would survive the holder's password change. With it, one needs the password **and** the mailbox, and the holder is warned.

Confirmation emails follow the spacing of §6.

From here on, a **registered device** is a browser whose witness is confirmed.

### 4.4 Renewal

The secret changes during the witness's life, so that an old copy ends up giving itself away.

- Renewal is a same-origin request to Lite's route, launched from the pages where the customer is logged in. A request another site can provoke never triggers it (the server reads the `Sec-Fetch-Site` header).
- **It works by confirmation**: the server hands out a new secret, and the old one stays valid until the new one comes back. A lost response or two open tabs therefore take nothing away from the holder.
- A secret from **this witness's history** that comes back after the grace period signals a copy: the witness **falls back to provisional** and is reconfirmed through the mailbox. An unknown secret does nothing. None of this blocks the account.

### 4.5 "My devices" and removal

The host displays, through the library's interface, the account's witnesses and each one's last visit. "Renew" only targets the current browser.

- **The holder removes a witness only with a confirmed witness**, never with the password alone: whoever stole the password cannot take away the holder's way back.
- **A witness only removes witnesses more recent than itself.** Seniority, kept on the server side, only serves to order removals.
- **The arbitrator can remove any witness**, including the oldest one when it is the stolen device.
- A witness the server has not seen for 400 days is erased.

**"Log out the other devices"** closes the door in a single press: all other sessions of the account are closed, and the witnesses more recent than the current browser's are removed. An older witness is not removed: its request goes to the arbitrator, with the timeline. Closing sessions is allowed to any open session, since anyone can log in again; removing a witness requires a confirmed witness. Without that bound, the thief of a registered device would make the same gesture against the holder, then change the password through the mailbox.

---

## 5. The link and the code

### 5.1 The link

The token is stateless:

```
token = HMAC-SHA256(server key, account ‖ address ‖ password hash ‖ timestamp)
```

Each field is encoded with its length, so that no concatenation reads two ways. The key lives outside the database.

- **The password hash** makes single use free: as soon as the password changes, every link issued before it dies, with no token table.
- **The address** kills links when the address changes.
- **The timestamp** bounds the link's life: **24 hours at most**.
- **The last login is not part of it.** It would let whoever holds the current password kill each of the holder's links by logging in again.

The link is built from a configured origin, never from the request's `Host` header. The token travels in the URL **fragment** (`#t=…`), which the browser sends neither to the server nor in the `Referer` header; the page also sets `Referrer-Policy: no-referrer`.

**Opening the link consumes nothing.** A GET has no effect: a mail scanner that follows the link does not burn it. Consumption is a same-origin POST, sent by the page after the proofs are checked. The password change is a **conditional write**: it happens only if the password in the database still matches the hash signed by the token. Of two simultaneous consumptions of the same link, only one goes through.

### 5.2 The code

The same email carries a code of **8 characters** drawn from an alphabet without ambiguous characters. It serves when the email is read where the link cannot be used: the in-app browser of a mail application, another device.

- It is worth what the link is worth, and lives as long.
- It is typed **on a registered device of the account** or, to confirm a registration, on the device to be registered.
- Its attempts are counted on the witness that types it: **5 attempts**, then the code dies. A third party without a witness cannot burn the holder's code.
- It appears neither in the subject nor in the first line of the email, so as not to show on a locked screen.

### 5.3 What no gesture does

**No gesture by the customer on one device — a link opened, a code typed — opens the session of another device.** Done on a registered device, it resets the password there; it activates nothing remotely. A path where one approves, on one device, the login of another is the one relay phishing exploits: the attacker makes the request, the victim approves it.

---

## 6. Sending emails

- **A minimum spacing per address**: the first email leaves at once; before each of the following ones, one must wait at least 2 minutes, then 10 minutes, 30 minutes, 2 hours, and from then on 4 hours. A request made too early receives the usual answer and sends nothing; the next email leaves at the next request, once the delay has passed. No scheduled task is needed.
- **A request carried by a confirmed witness** leaves outside the spacing: a third party flooding the address does not delay the holder recovering from their device. These sends are bounded per witness and per day.
- **Notices** (recovery done, device confirmed, address change) escape the spacing: they arise from an account event, which a third party does not provoke.
- **The request answers the same way, and in the same time, whether the account exists or not.** No email leaves for an address without an account. Sending happens after the response when the host allows it; otherwise, the timing gap due to sending is declared as a limit of the host, and gets measured.
- **Each email carries a priority**, so that the host protects its sending quota.

---

## 7. The recovery paths

### 7.1 The fast path: the link and a witness

The customer requests a link. They open it on a registered device of the account, or type the email's code on one of them, then choose a new password. This is the expected path in most cases, and it asks nothing more than the usual "forgot password".

The request can be made on one device and finish on another: requested on the computer, the recovery ends on the registered phone that reads the email.

### 7.2 New device, with the word

The **word** is optional. The customer chooses it in their account whenever they wish; an information bubble explains why to choose it right away rather than after a loss, and recommends **a word specific to this site**, strongly so when the site encrypts its data with SelfDataGuard. It is also offered after a recovery.

- **Derivation**: in the browser, as in SelfRecover (`sr-derive.js`, `hostname` mode; SelfRecover whitepaper, §4). The word never leaves the browser; only its digest does. The word's page redirects to the **canonical host** the site declares: the bare domain, `www.` and `m.` would give three different digests.
- **Strength**: at least 8 characters, and never a single word from the word lists; the page checks this before sending.
- **The salt is served only behind a valid link.** Without the mailbox, one does not get what is needed to compute a digest.
- **Attempts**: 5 per link, and a budget per account and per day counted **only through a valid link**. No one without the mailbox fills that budget in the holder's place.
- **The pepper**: the received digest goes through an HMAC keyed by a server secret stored outside the database, before Argon2id hashing (§9.1).

The link and the word give a new password. They do not register the browser: registration then goes through the same question as at any login.

### 7.3 New device, without the word: level 3

The last path goes through a human, the **arbitrator**: the site's customer service.

- **The proof of the mailbox is valid for the browser that presents it.** The case opens with the link, in the browser that made the request.
- **Precedence rule**: without a witness, a link is valid only for a request **created before the email was sent**. The reader of a forwarded email, who would request after reading it, is refused.
- **Cases are not exclusive**: several cases on the same account coexist, and the arbitrator sees them grouped. A third party cannot take the holder's place.
- **The evidence**: the arbitrator judges on facts the mailbox does not reveal. What the site's emails reveal (orders, delivery addresses) proves nothing, since the holder of the mailbox reads them too.
- **A delay before the approval takes effect**: 72 hours by default; the site sets it between 48 hours and 7 days, before installation (§3). During that delay, the holder is **notified**: an email to the account's address as soon as the approval is given, and a notice on each registered device at its next visit.
- **Objecting**: a witness confirmed before the request can object. The objection **suspends** the approval and sends the case back to the arbitrator. It is never a veto: a stolen device does not, on its own, block a legitimate recovery.

---

## 8. After a recovery

### 8.1 The notice

Every recovery notifies the holder, at the account's address and on their registered devices.

### 8.2 The return window

- It is **anchored on the recovery** and lasts **14 days** by default; the site sets it between 7 and 30 days, before installation (§3).
- It closes **only at its end or through the arbitrator**. A return cancels the recovery **without closing the window**: otherwise a thief holding the mailbox and an old registered device would redo a recovery after each return, and the account would change hands endlessly. Nothing else moves it.
- **During the window, the password changes only through a return**: neither a normal change nor a second recovery takes place in it. The return and appeal links are **signed on the window**, not on the password hash: whoever recovered cannot kill them by changing the password.
- The account is flagged "under return". The library refuses address changes. The host should also freeze new delivery addresses and new payment methods: that is how an impostor profits from a shop account.
- **An address change opens a window too**: the old address and all witnesses are notified, and the return goes through the old address.

### 8.3 The return

The return is the fast path, with one more condition: **the link and a witness confirmed before the recovery.**

- It **cancels the effects of the recovery**: the witnesses registered since are removed, the sessions closed, and everyone is notified.
- **A second return within the same window, or a contested return, goes to the arbitrator.** Two old devices do not bounce the account back and forth.
- The holder who came back then closes the door with "log out the other devices" (§4.5). The price: until the end of the window, they cannot change their password on their own either.

### 8.4 The old password: an appeal, never an automatism

The old password (the one from before a recovery; a normal change retires it) **opens an appeal to the arbitrator**, through an entry specific to Lite: the appeal link and the old password. This entry escapes the host's login brake, which a third party could fill to shut the holder out.

It never triggers anything automatic: no return, no lock, no hardening. After a credential theft, the "old" password may be the attacker's. Refusing a wrong old password costs the same time as any other refusal (a dummy hash is computed), and attempts are counted per appeal link: the entry is not an endless oracle.

Outside the appeal, the old password only serves to reopen the archive of a SelfDataGuard vault (§9.2): it gives back there only what it already opened before the recovery.

The arbitrator sees the timeline the library keeps: who recovered, when, from what, and who is appealing.

### 8.5 The limit

An attacker who held the password **and** the mailbox before the recovery, and used them to register and confirm a device, cannot be told apart from the holder. The arbitrator decides on the timeline.

---

## 9. The pepper and encrypted data

### 9.1 The pepper

A server secret, stored outside the database, that enters the word's hash (§7.2) and the vault's wraps (§9.2).

- **Local mode, by default**: a file outside the directory served by the web, of at least 32 bytes, whose absence or weakness makes the library fail loudly; never in the database backup; a copy off the server; the database account has no right to read files.
- **Remote mode, optional**: another machine holds the pepper and returns only computations; the site's server never sees it.

What the pepper changes: a stolen database alone is not enough to try words offline. What it does not change: a fully compromised server holds the local-mode file. Its loss makes the word's hashes and the witnesses' wraps unusable; hence the copy off the server.

### 9.2 The SelfDataGuard vault

A site that encrypts its customers' data with SelfDataGuard seals each vault's key under several locks. With a forgotten password, the password lock is lost; with no other lock, the vault would become unreadable for everyone. Lite adds some.

- **Each confirmed witness also seals the vault**: a wrap whose key is computed on the server side from the witness's secret and the pepper, resealed at each renewal of the witness. **The fast path keeps the data.**
- The word, if there is one, is a lock, as in SelfDataGuard.
- **Archived wraps** are resealed at each renewal, like the live ones: a witness from before that comes back finds the archive again.
- **Level 3 without the word**: the old vault is **archived with all its wraps**. It reopens with **any lock from before**: the old password, a witness confirmed before the recovery, or the word. Witnesses registered after the recovery do not open it; whoever recovered through the arbitrator starts again from an empty vault.
- **Optionally**, SelfDataGuard's escrow (a keeper off the machine), for the sites that can afford it.

As for all SelfDataGuard locks, the server opens the vault for the duration of a request. The cookie's restricted path limits the requests that carry a witness's secret.

---

## 10. What the library imposes on the host

A guarantee written in an integration guide and left to the host ends up forgotten without a sound. Lite therefore takes charge of what its guarantees depend on.

- **Changing the password, changing the address, closing sessions** are functions of the library. It knows whether a change is normal or comes from a recovery, checks the window, refuses what is frozen, and **closes all other sessions** at every password or address change.
- **Changing the email address is a recovery gesture, at all times**: a click in an email sent to the **old** address, **and** a confirmed witness or the word. Never the password alone. Without the old mailbox, the arbitrator.
- **A conformance bench is delivered to the host**, to run at installation and in its continuous integration: it checks that no known route changes the password or the address without going through Lite.
- **A detector of changes outside Lite**: the library remembers the password hash and the address it set. If it finds them changed without it (a back office, a direct database query), it reports it and notifies the holder. The bench sees the routes it knows; the detector sees the result.
- **Storage**: every threshold compared with an expression is bound as an integer (SQLite compares a value bound as text differently); every table a name can cause to be written is bounded by construction; the required isolation level is declared for each engine (SQLite, MariaDB, PostgreSQL).

---

## 11. The threat model

### 11.1 The adversaries

| adversary | what they hold | what they get | what stops them |
|---|---|---|---|
| the third party | the holder's email address | requests, spaced; no answer that tells an existing account apart | the spacing per address; no counter that refuses per account; a request carried by a witness goes outside the spacing |
| the link reader | a forwarded email, a scanner, an intercepted link | nothing without a witness or the word; at level 3, nothing if they requested after the email was sent | the witness; a GET that consumes nothing; the precedence rule |
| the mailbox holder | the mailbox, without a device or the word | a level-3 case, judged on facts outside the mailbox, after a delay in which the holder is notified and can object; if it goes through, a window (14 days by default) in which the holder comes back through a device from before | the witness; the evidence; the delay; the window |
| the password thief | the site's password | ordinary login, outside Lite's scope; a provisional witness that opens nothing | confirmation through the mailbox; the password alone removes no witness, changes neither the address nor the word |
| the cookie thief | the copied witness, without the mailbox | nothing alone; presented after a renewal, the witness falls back to provisional | the mailbox; renewal by confirmation |
| the cookie and mail-session thief | the witness and the mailbox | a recovery through the fast path | the recovery notice; the return through another device from before; declared limit (§12) |
| the stolen unlocked phone | the witness and the mailbox in the same pocket | a recovery | declared limit; the arbitrator removes the witness; the return from another device from before |
| the shared computer | a common browser | nothing on someone else's account: one witness per account, and a registration never removes someone else's | the question at registration; confirmation through the mailbox |
| the stolen database | the tables | no witness secret (hashes only), no offline attempt at the word without the pepper, no token to replay (stateless, key outside the database) | the witnesses' hashes; the pepper and the HMAC key outside the database |
| the compromised server | the code, the key and the local pepper | the secret of the witnesses that present themselves during the compromise, on Lite's routes only | the cookie's restricted path; the pepper's remote mode; a limit common to any server-side library |
| the customer-service employee | the arbitrator's console | a level-3 approval, visible to the holder: delay, notice, objection, window | the delay and the notice; the timeline kept by the library |
| the integrator who makes a mistake | a route that changes the password without Lite | a guarantee broken without a sound | the conformance bench; the change detector |

### 11.2 The third party's three questions

Each door of Lite is examined with three questions: with only someone else's public identifier, what can one trigger on their account? And if one starts again endlessly? And with part of a secret, what does each wrong attempt cost?

| door | the identifier alone | endlessly | part of the secret |
|---|---|---|---|
| link request | a request, same answer as for a nonexistent account | the spacing bounds emails per address | — |
| opening the link | nothing: a GET consumes nothing | — | the token is an HMAC-SHA256 |
| code | nothing without a witness of the account | attempts counted on the witness that types | 5 attempts, then the code dies |
| word | the salt is served only behind a valid link | 5 attempts per link; a per-account budget filled only through a valid link | bounded attempts; peppered hash |
| registration | nothing without the password; provisional without the mailbox | confirmation emails spaced | — |
| renewal | nothing without the secret | a request coming from another site is ignored | an unknown secret does nothing |
| removal | nothing without a confirmed witness | — | — |
| level 3 | nothing without the mailbox; non-exclusive cases | cases grouped for the arbitrator | — |
| appeal | nothing without the appeal link | attempts counted per link | same refusal time |

The common principle: **no counter refuses, per account, what a third party can fill.** The implementation's benches will replay each line of this table, in simulated time.

---

## 12. The accepted limits

- **Email remains mandatory.** Every recovery starts with the link sent to the account's address: Lite adds a second proof to email, it does not do without it. Whoever loses access to their mailbox can then change address only through the arbitrator; whoever takes it already holds one of the two proofs. To remove the mailbox from the recovery circuit, SelfRecover is needed.
- **The stolen unlocked phone** often carries the witness and the mailbox: it recovers the account. The holder is notified, and comes back through another device from before during the window, or through the arbitrator.
- **The same password for the mailbox and for the site**: nothing in this module stops it at login. Two-factor authentication at login would; it is outside Lite's scope.
- **Private browsing and in-app browsers** have no lasting witness: they go through the code typed on a registered device, through the word, or through the arbitrator.
- **Without SelfDataGuard**, the site's data in clear is readable by whoever holds the account.
- **A customer with no device from before, no old password and no word** loses the archive of their SelfDataGuard vault.
- **The attacker who held the password and the mailbox** before the recovery cannot be told apart from the holder (§8.5).
- **Whoever has lost access to their mailbox** goes through the arbitrator to change address: no automatic path opens without the mailbox.
- **The share of customers without a witness** (new device, cookies cleared) sets how many recoveries go through the word or the arbitrator. It will be measured at a real site.

---

## 13. The variants set aside

These variants were examined during the design.

| variant | reason for rejection |
|---|---|
| **The link alone**, the usual path | whoever reads the mailbox gets the account (§1) |
| **The witness alone**: a known device is enough to reset | a stolen device or a copied cookie would be enough |
| **The known device as a signal**, weighed with others | a small site has no other signals, and a weighed signal is bypassed without a sound |
| **The link or the code bound to the requesting browser only**, without a witness | stops the scanner, not the mailbox holder, who makes their own request and reads their own email |
| **The reversed code**: shown on the requesting screen, typed into the page opened from the email | an action on one device would open the session of another: the relay-phishing pattern (§5.3) |
| **A takeover through the mailbox alone, undone afterwards by the old password** | after a credential theft, the "old" password may be the attacker's, and a leaked password undoes a legitimate recovery |
| **Access granted automatically after a delay** | whoever holds the current password cancels every wait, and the holder without a device stays locked out |
| **Immediate registration at login** | a stolen password would set a witness that survives; hence the provisional witness, confirmed through the mailbox |
| **A quarantine of a few days for a new witness** | replaced by confirmation through the mailbox, which costs an email instead of a wait |
| **One word for all the sites of the family** | with SelfDataGuard, the word opens the vault; a script skimming the page of a less protected site would capture it for all the others |
| **"Every password change removes the witnesses"** | the password thief would take away the holder's way back |
| **The last login in the link's token** | whoever holds the password would kill each of the holder's links by logging in again |
| **An account risk state, an automatic freeze** | a lever the third party pulls against the holder |
| **A per-account failure counter that refuses** | the third party would burn the holder's attempts |
| **A cookie with a fixed name** | on a shared browser, registering one account would overwrite someone else's witness |
| **Script-written storage, or a WebCrypto key** | erased by some browsers after seven days of use without interaction with the site |
| **SelfRecover's "device" factor**: a device key, kept by the browser, that recovers the account without email | it would duplicate the witness (the device) and the word (what one knows); it lives in script-written storage, which some browsers erase after seven days without interaction with the site; and it would recover without the link, which Lite forbids |
| **A single place per account at level 3** | a third party would occupy it and push the holder aside; hence non-exclusive cases |
| **Seniority as ownership** | the oldest stolen device could no longer be removed; hence the arbitrator, who can remove any witness |
| **A standalone module that copies SelfRecover's hashing** | two hashing profiles would end up diverging; the Argon2id profile stays single |
| **A return that closes the window** | the thief of an old registered device, who also holds the mailbox, would redo a recovery after each return: the account would change hands endlessly |
| **A button that removes all other witnesses, older ones included** | the thief of a registered device would press it first, then change the password through the mailbox: the holder would have neither password nor witness |

---

## 14. Prior art

Each brick of Lite is published elsewhere, and this document cites them. What the design has of its own lies in their assembly. Sources read on 10 November 2026.

| brick | published by | what Lite does with it |
|---|---|---|
| a random secret set at login, replaced at each use | Charles Miller, "Persistent Login Cookie Best Practice", 19 January 2004 | the witness |
| reuse of a replaced secret signals a theft | Barry Jaspan, "Improved Persistent Login Cookie Best Practice", 1 November 2006; IETF RFC 9700 (January 2025), §4.14.2 | the witness falls back to provisional, without blocking the account |
| a cookie issued at a successful authentication, which spares the next login an extra challenge (an anti-bot test in Pinkas and Sander, lockout in OWASP) | B. Pinkas and T. Sander, "Securing Passwords Against Dictionary Attacks", ACM CCS 2002; OWASP, "Slow Down Online Guessing Attacks with Device Cookies" (A. Dedov), 2015 | nothing at ordinary login: the witness serves recovery only |
| recovery conditioned on a previously used device | patent application US 2012/0166553 A1, "Using social graph for account recovery" (priority of 23 December 2010); documented practice of large account providers, who make it one signal among others or a sufficient proof | neither a signal nor a sufficient proof: one of the two keys, and any registered device of the account |
| the link bound to the requesting browser | the PKCE principle (IETF RFC 7636) applied to email login links, in use at identity providers | set aside as a mechanism on its own (§13); kept at level 3: the proof is valid for the browser that presents it |
| a code and a link in the same email | documented practice of identity providers, where the code is typed into the requesting browser | the code is typed on any registered device of the account |
| the stateless token, signed on the password hash, the last login and the address | the password-reset token generator of the Django framework | kept, without the last login (§5.1) |
| deriving a word by a pseudo-random function keyed by the password and applied to the domain name | B. Ross, C. Jackson, N. Miyake, D. Boneh, J. C. Mitchell, "Stronger Password Authentication Using Browser Extensions" (PwdHash), USENIX Security 2005 | SelfRecover's derivation, kept for the word |
| notifying every recovery; an email code valid for 24 hours at most | NIST SP 800-63B-4 (July 2025), §4.2.3 and §4.2.1.2 | kept |
| closing sessions on password change | OWASP, "Forgot Password Cheat Sheet"; Django documentation | imposed by the library (§10) |
| the cancellation window, the trusted third party, human review | social recovery of wallets with a cancellation window; the "trusted referee" of NIST SP 800-63A-4 (July 2025, §2.1.2 and §3.15.1), taken up by the FIDO Alliance in "Recommended Account Recovery Practices for FIDO Relying Parties" (February 2019); human review at large providers | the arbitrator, the delay before approval, the return window |

**What the assembly has of its own**, according to the sources read only:

1. the rule "the link and a witness, never one without the other", where the witness is **any** registered browser of the account, and not only the requesting browser;
2. the substitution grid, always with the link: witness, then word derived from the host name, then human arbitrator;
3. refusing that a link alone registers a browser, where published practice does the opposite; provisional registration, confirmed through the mailbox in the browser itself;
4. after recovery: return and appeal links signed on the window, the return through a witness from before, the old password reduced to an appeal to the arbitrator;
5. the encrypted vault sealed by each witness, and its archive reopened by any lock from before.

---

## 15. Status and next steps

- This document precedes the code. The implementation will follow with its benches: a third-party bench in simulated time, the conformance bench delivered to the host, multi-process benches for conditional writes, on SQLite, MariaDB and PostgreSQL.
- A design change goes through a new dated edition of this document.
- SelfRecover-Lite will be published under the AGPL-3.0-or-later licence, like the other MySelf modules.

**GitHub:** https://github.com/Pierroons/my-self

---

## Appendix A — The procedure, step by step

### A.1 Registering a witness

1. The customer logs in by password from a browser without a witness of the account.
2. The host asks whether they want to register this device; a refusal is remembered in this browser.
3. On agreement, the server draws 32 random bytes, sets them in a cookie specific to the account (§4.1), stores their hash and marks the witness provisional.
4. The library returns a confirmation email, with a link and a code, to the account's address; the host sends it, under the spacing of §6.
5. The link opened in the browser that carries the provisional witness, or the code typed in that browser, confirms the witness.
6. The confirmation is announced to the account's address and to the other confirmed witnesses.

### A.2 Recovering an account

1. The customer gives their address. The answer is the same whether the account exists or not, and takes the same time when the host sends the email after the response (§6). Under the spacing, or outside it if the request carries a confirmed witness, the library returns an email carrying a link (stateless HMAC token, in the URL fragment) and a code.
2. On a device that carries a confirmed witness of the account, the link's page reads the token in the fragment and sends it by a same-origin POST, with the witness's cookie. The server checks the token (signature, age of 24 hours at most, password hash still in place) and the witness (known hash, confirmed, same account). The new password is written on condition that the signed hash is still the database's. On a device that carries the witness but not the email, the typed code stands in for the link.
3. Without a witness, with the word: behind a valid link, the server serves the salt; the browser derives the digest on the canonical host; the server passes it through the pepper and compares it to its Argon2id hash, under the attempt bounds of §7.2.
4. Without a witness or the word: a level-3 case opens with the link, in the requesting browser, if the request precedes the sending of the email. The arbitrator judges on a body of facts outside the mailbox. Their approval takes effect after the delay set by the site; the holder is notified as soon as the approval is given, and a witness confirmed before the request can suspend it.
5. Once the recovery is done, the notice goes to the account's address and to the registered devices, and the return window opens.

### A.3 During the return window

1. The password changes only through a return; the address does not change; the other sessions are closed at each change.
2. The return: a link signed on the window and a witness confirmed before the recovery. It cancels the effects of the recovery and notifies everyone. A second return, or a contested return, goes to the arbitrator.
3. The appeal: a link signed on the window and the old password open a case before the arbitrator, with no automatic effect.
4. The window closes at its end or through the arbitrator; a return does not close it.

---

*SelfRecover-Lite — email recovery, with a second key the mailbox does not give.*
