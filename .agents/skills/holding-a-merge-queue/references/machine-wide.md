# Before anything that affects the whole machine

A restart, a keep-awake change, or a container teardown suspends every session,
not only yours.

1. Ask every session listed by `ListAgents`, not only the ones you have been
   talking to. A queue holder rarely knows about all of them.
2. Require an explicit yes. **Silence reads the same as busy.**
3. Ask for three specifics: no agents running, nothing local in flight,
   everything pushed, with nothing left only in a worktree.
4. Offer the clean stop. A session that kills its own agent deliberately and
   pushes what it has beats one suspended mid-gate.
5. The decision is the owner's, not yours and not the peers'. Put the cost of
   both options to them and let them choose.
