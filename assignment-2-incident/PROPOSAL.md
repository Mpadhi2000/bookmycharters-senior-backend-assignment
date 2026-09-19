# Assignment 2 — Production Incident Proposal

## 1. Read of the Log

**Incident Summary:**
The Yii2 application is experiencing intermittent CPU and memory spikes due to invalid traffic (approx. 100 requests/minute). Currently, Nginx passes all requests, including those for non-existent URLs, to Yii2. Bootstrapping the PHP framework to handle 404s for vulnerability scanners is resource-intensive and is a significant contributor and the first issue I would mitigate.

**Traffic Classification Table:**

| Source IP       | Path                                     | User-Agent              | Classification   | Evidence / Reasoning                                                                                                                       |
| :-------------- | :--------------------------------------- | :---------------------- | :--------------- | :----------------------------------------------------------------------------------------------------------------------------------------- |
| `203.0.113.44`  | `/wp-admin/`, `/xmlrpc.php`, `/test.php` | `Mozilla/5.0 zgrab/0.x` | hostile          | Known scanner (`zgrab`) looking for WordPress and generic test files.                                                                      |
| `198.51.100.7`  | `/.env`, `/phpmyadmin/`, `/api/debug`    | `python-requests/2.31`  | hostile          | Automated script searching for sensitive configuration and admin interfaces.                                                               |
| `45.146.164.2`  | `/admin/login.php`, `/random-string`     | `-` (Empty)             | benign-but-noisy | Automated scanning with no User-Agent. `/random-string` might be a probe to check how the server handles 404s.                             |
| `110.226.180.5` | `/flights/mumbai-delhi`, `/.env`         | `Mozilla/5.0 (iPhone)`  | legitimate       | Makes a valid customer request (200 OK) and a suspicious request (404). Likely a shared IP (CGNAT/NAT) or a compromised legitimate device. |
| `216.144.250.9` | `/health`                                | `UptimeRobot/2.0`       | legitimate       | Uptime monitoring service.                                                                                                                 |
| `104.18.22.33`  | `/webhooks/razorpay`                     | `Razorpay-Webhook/1.0`  | legitimate       | Payment gateway webhook delivery.                                                                                                          |
| `66.249.66.1`   | `/sitemap.xml`                           | `Googlebot/2.1`         | legitimate       | Search engine crawler.                                                                                                                     |

**Why naive IP blocking is dangerous:**
IP `110.226.180.5` is the source of both a legitimate customer request (`/flights/mumbai-delhi`) and a suspicious request (`/.env`). Blocking this IP would block real users, which is highly likely if the IP belongs to a mobile carrier (CGNAT) or a corporate NAT.

**Why naive user-agent blocking is dangerous:**
Attackers easily spoof User-Agents. The request for `/.env` from `110.226.180.5` uses a standard `Mozilla/5.0 (iPhone)` User-Agent. Blocking it would block all legitimate iPhone users.

**Why blindly rate-limiting all traffic is dangerous:**
A blanket rate limit could drop critical asynchronous traffic like Razorpay webhooks (leading to unfulfilled orders) or UptimeRobot checks (triggering false downtime alerts). It could also impact legitimate users during traffic spikes.

**Important assumptions and unknowns:**

- We assume the provided log is representative of the 100 req/min traffic causing the spikes.
- We assume Nginx is currently configured with a catch-all `try_files $uri $uri/ /index.php?$args;` that sends everything not found on disk to Yii2.
- We assume Razorpay and UptimeRobot IPs/User-Agents are consistent and can be identified.

## 2. The Next Two Hours

**Goal:** Reduce unnecessary Yii2/PHP processing by handling clearly invalid/sensitive requests as early as safely possible, without impacting legitimate traffic.

**Exact Change:**
Update the Nginx configuration to intercept requests for known hostile paths and hidden files, returning a `404 Not Found` or `403 Forbidden` directly from Nginx, bypassing PHP-FPM/Yii2 entirely.

```nginx
# Block hidden files (like .env, .git) directly in Nginx
# Note: Legitimate infrastructure paths such as /.well-known/ must be excluded/verified before enforcement.
location ~ /\. {
    access_log /var/log/nginx/blocked.log;
    deny all;
}

# Block common scanner paths directly in Nginx
location ~* ^/(wp-admin|wp-login|xmlrpc\.php|phpmyadmin|admin/login\.php|test\.php|api/debug) {
    access_log /var/log/nginx/blocked.log;
    return 404;
}
```

_Note: The broad `location ~ /\. ` rule is not universally safe. Legitimate infrastructure paths such as `/.well-known/` must be explicitly excluded or verified before enforcement._

**Why Nginx is an appropriate layer:**
Nginx is highly optimized for static routing and regex matching. Nginx rejects the request before PHP-FPM/Yii2, avoiding framework bootstrap and application routing work, which mitigates the CPU/memory spikes. Separating blocked requests into a dedicated Nginx log ensures we maintain visibility during the incident to measure whether the mitigation is working.

**Protection of Legitimate Traffic:**

- **Legitimate customer traffic:** Routes like `/flights/mumbai-delhi` do not match the blocked regex and will continue to be passed to Yii2.
- **`/health`:** Does not match the blocklist; continues to function.
- **`/webhooks/razorpay`:** Does not match the blocklist; continues to function.
- **Googlebot/sitemap:** `/sitemap.xml` does not match the blocklist; continues to function.

**Deployment Sequence:**

1. SSH into the EC2 instance.
2. Backup the current Nginx config: `cp /etc/nginx/nginx.conf /etc/nginx/nginx.conf.bak`.
3. Apply the location blocks to the relevant server block.
4. Test the configuration: `nginx -t`.
5. Reload Nginx gracefully: `nginx -s reload`.

**Validation Checks:**

- **Before:** `curl -I https://domain/.env` (should show PHP/Yii2 headers or take longer).
- **After:** `curl -I https://domain/.env` (should return 403 immediately). `curl -I https://domain/health` (should return 200).

**Rollback Procedure:**
If issues arise, restore the backup (`mv /etc/nginx/nginx.conf.bak /etc/nginx/nginx.conf`) and run `nginx -s reload`.

**Metrics to Watch:**

- EC2 CPU and Memory utilization (should drop and stabilize).
- Nginx access/error logs.
- Yii2 application logs (should see a significant reduction in 404 errors).

## 3. Durable Fix — This Week

**Goal:** Implement a permanent, maintainable solution within one week for two engineers, minimizing AWS spend.

**1. Nginx Request Handling & File Extension Filtering**
Instead of attempting a fragile strict route allowlist, we should configure Nginx to prevent clearly suspicious or non-existent file-style requests from reaching Yii2.

- Configure Nginx to directly return a 404 for requests containing specific file extensions (e.g., `.php`, `.env`, `.zip`, `.sql`, `.bak`) that do not exist on disk.
- Normal Yii2 extensionless application routes will continue to reach the application.
- _Validation:_ This rule must first be validated against the application's legitimate static files and existing Nginx configuration to ensure no valid assets are blocked. We cannot assume every request with an extension can always be rejected safely without testing.

**2. Targeted Rate Limiting (Evidence-Driven)**
A blanket rate limit (e.g., 10 req/sec per IP) is dangerous because of the shared-IP problem. As seen with `110.226.180.5`, a single IP can make both legitimate customer requests and suspicious requests (e.g., CGNAT or corporate NAT).

- If rate limiting is necessary after implementing file extension filtering, it must be targeted rather than blindly applied to all traffic.
- Apply limits only to sensitive or high-risk endpoints (like login or search).
- Thresholds must be validated against real traffic patterns, not guessed.
- Explicitly bypass critical services (e.g., UptimeRobot, Razorpay webhooks).

**3. Logging and Observability**

- Separate Nginx logs for blocked requests vs. legitimate requests to reduce noise.
- Ensure Yii2 logs include execution time and memory usage to monitor performance bottlenecks.
- Set up CloudWatch Agent on the EC2 instance to stream Nginx and Yii2 logs, and monitor memory (which isn't tracked by default EC2 metrics).

**4. Monitoring and Alerting**

- Create CloudWatch Alarms for CPU > 70% and Memory > 80%.
- Create an alarm for a high rate of 5xx errors from Nginx.

**Cost and Engineering Effort:**

- **Cost:** Minimal. CloudWatch logs and custom metrics (memory) will incur a small monthly charge. We avoid managed AWS protection (like AWS WAF, ALB, or CloudFront) because they introduce additional infrastructure and recurring/request-based costs. The assignment asks us to minimize AWS spend, so the first implementation should use the existing EC2 + Nginx architecture.
- **Effort:** The work should fit within the one-week constraint for 2 engineers, subject to existing configuration validation for file extension filtering, Nginx setup, and CloudWatch.

**Alternatives Considered:**

- **AWS WAF:** Would automatically block known bad IPs and scanners using Managed Rules. _Trade-off:_ Requires moving Nginx behind an ALB or CloudFront, significantly increasing AWS costs and architectural complexity. Rejected due to the constraint to minimize AWS spend.
- **Fail2Ban:** Could parse Nginx logs and block IPs at the firewall level. _Trade-off:_ High risk of blocking shared IPs (like `110.226.180.5`), leading to legitimate user lockouts. Rejected due to safety concerns.

## 4. Weakest Point in My Plan

**The Weakest Point:** Nginx filtering based on request characteristics can miss attackers who use extensionless URLs.

**What could go wrong:**
Attackers could request extensionless paths such as `/admin`, `/backup`, or `/api/v2/users`. Because these do not match the file extension filter, they would still reach Yii2, potentially consuming resources and causing CPU/memory spikes if the volume is high enough.

**Impact on customers/integrations:**
If extensionless scanning volume increases significantly, the application could experience the same performance degradation, leading to slow response times or timeouts for legitimate customers and integrations.

**Detection:**
We must monitor:

- Remaining 404 volume in Yii2
- CPU and memory utilization
- PHP-FPM/application load
- Latency
- 5xx error rate

**Mitigation/Rollback:**

- First, measure the remaining traffic and identify repeatable patterns.
- Add narrowly targeted Nginx handling only when evidence supports it.
- If traffic volume and threat level eventually justify the cost, consider migrating to a managed edge/WAF solution later.

## Assumptions

1. **Razorpay and UptimeRobot IPs are known/static:** We assume we can obtain the official IP ranges for these services to whitelist them from rate limiting. _Verification:_ Check their official documentation and verify against historical access logs before applying rate limits.
2. **Static file configuration can be validated:** We assume the application's legitimate static files and existing Nginx configuration can be verified before enforcing file-extension filtering. _Verification:_ Review the Nginx configuration and test the file extension rules against known legitimate static assets to ensure no valid files are blocked.
3. **Nginx is the only web server:** We assume Nginx is directly serving the traffic and communicating with PHP-FPM, without another proxy in between that might obscure the real client IP. _Verification:_ Check Nginx configuration for `X-Forwarded-For` headers and ensure IPs in logs are actual client IPs.

## Engineering Reasoning

1. **The Bottleneck:** The CPU and memory spikes are not caused by network bandwidth, but by the application layer. Bootstrapping a full PHP framework (Yii2) just to determine that `/wp-admin` doesn't exist is a massive waste of compute resources.
2. **Early Rejection:** By stopping invalid requests at Nginx, we handle them in C (Nginx) rather than PHP. Nginx rejects the request before PHP-FPM/Yii2, avoiding framework bootstrap and application routing work, keeping the matched requests from reaching the application layer.
3. **IP/UA Blocking is Insufficient:** The logs clearly show a shared IP (`110.226.180.5`) making both valid and invalid requests. Blocking by IP or User-Agent is a blunt instrument that will inevitably cause collateral damage to real users.
4. **Protecting Legitimate Traffic:** Business-critical endpoints (webhooks, health checks) must be explicitly protected because their failure can disrupt payment processing or operations (false downtime).
5. **Emergency vs. Durable:** The emergency fix uses a quick blocklist to stop the bleeding immediately with minimal risk. The durable approach is narrow Nginx request filtering, targeted/evidence-driven rate limiting, observability, and monitoring, which is more robust against evolving attacks but requires careful planning and testing to avoid breaking the app.
6. **Observability and Rollback:** Any infrastructure change carries risk. Having a clear rollback command (`nginx -s reload` with a backup config) and metrics to watch ensures that if we make a mistake, we can undo it in seconds before it causes a major outage.
7. **Constraints:** The proposed solution relies entirely on Nginx, which is already present. It avoids introducing additional edge infrastructure such as ALB, CloudFront, or WAF at this stage, perfectly aligning with the "minimize AWS spend" constraint, and is easily achievable by two engineers in a week.
