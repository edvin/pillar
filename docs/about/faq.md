# ❓ FAQ

**Do I need event sourcing for every aggregate?**  
No. Use state‑based repositories where audit trails/replay aren’t needed.

**Can I mix event‑sourced and state‑based aggregates?**  
Yes. Configure repositories per aggregate class.

**How do I rename an event?**  
Keep data stable by using **event aliases**. If the shape changes, bump a **version** and add an **upcaster**.

**How do I handle huge aggregates?**  
Use snapshots and choose an appropriate **fetch strategy** (chunked/streaming).

**Is replay safe?**  
Only listeners implementing `Projector` are invoked during replay. Side‑effecting listeners are skipped.
