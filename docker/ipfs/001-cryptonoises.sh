#!/bin/sh
# Configures the optional `ipfs` sidecar (compose profile "ipfs") as a disk-bounded read cache for the workers.
# Runs on every container start, after `ipfs init` on the first one, so a changed IPFS_* setting applies on restart.
set -e

# The server profile (no LAN discovery, no dialing private addresses: hosting providers report that as port scans),
# applied again on every start so that the filters below always start from it
ipfs config profile apply server >/dev/null

# Private ranges the node may dial anyway, for a peer on your own network (IPFS_PEERING): IPv4 ranges exactly as the
# profile filters them, comma-separated, e.g. 192.168.0.0/16. Peers that other nodes announce in those ranges become
# dialable too, so only name ranges where that is harmless.
if [ -n "${IPFS_DIAL_PRIVATE_RANGES:-}" ]; then
  filters=$(ipfs config Swarm.AddrFilters | grep -o '"/ip[46]/[^"]*"')
  for range in $(echo "$IPFS_DIAL_PRIVATE_RANGES" | tr ',' ' '); do
    entry="\"/ip4/${range%/*}/ipcidr/${range#*/}\""
    echo "$filters" | grep -qxF "$entry" || echo "IPFS_DIAL_PRIVATE_RANGES: $range is not one of the filtered ranges" >&2
    filters=$(echo "$filters" | grep -vxF "$entry" || true)
  done
  ipfs config --json Swarm.AddrFilters "[$(echo "$filters" | tr '\n' ',' | sed 's/,$//')]"
fi

# The admin API stays inside this container; only the read-only gateway is reachable, on the compose network
ipfs config Addresses.API /ip4/127.0.0.1/tcp/5001
ipfs config Addresses.Gateway /ip4/0.0.0.0/tcp/8080
ipfs config --json Gateway.ExposeRoutingAPI false

# Garbage collection. Nothing is pinned, so everything fetched is cache and collectable. With `daemon --enable-gc`
# (compose.yaml) kubo checks every GCPeriod whether the repo is past StorageGCWatermark % of StorageMax, and then
# removes every unpinned block. StorageMax is a soft limit: between two checks the repo grows by whatever the
# workers fetch, so leave that much disk free on top of it.
ipfs config Datastore.StorageMax "${IPFS_STORAGE_MAX:-20GB}"
ipfs config --json Datastore.StorageGCWatermark "${IPFS_GC_WATERMARK:-80}"
ipfs config Datastore.GCPeriod "${IPFS_GC_PERIOD:-10m}"

# A cache, not a server: find content through the DHT and delegated routers without serving DHT queries, and do not
# announce the blocks it caches (they come and go with every collection).
ipfs config Routing.Type autoclient
ipfs config --json Provide.Enabled false

# Optional peers to stay connected to - a node that holds the content you fetch most, say - as a JSON array of
# {"ID": "<peer id>", "Addrs": ["<multiaddr>"]}
ipfs config --json Peering.Peers "${IPFS_PEERING:-[]}"
