# ZAP Scanning Report

ZAP by [Checkmarx](https://checkmarx.com/).


## Summary of Alerts

| Risk Level | Number of Alerts |
| --- | --- |
| High | 0 |
| Medium | 1 |
| Low | 3 |
| Informational | 10 |




## Insights

| Level | Reason | Site | Description | Statistic |
| --- | --- | --- | --- | --- |
| Info | Informational | https://firefox-settings-attachments.cdn.mozilla.net | Percentage of endpoints with content type text/plain | 100 % |
| Info | Informational | https://firefox-settings-attachments.cdn.mozilla.net | Percentage of endpoints with method GET | 100 % |
| Info | Informational | https://firefox-settings-attachments.cdn.mozilla.net | Count of total endpoints | 1    |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of responses with status code 2xx | 94 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of responses with status code 3xx | 4 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of responses with status code 4xx | 1 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with content type application/javascript | 6 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with content type font/woff2 | 18 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with content type image/svg+xml | 6 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with content type image/webp | 6 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with content type text/css | 6 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with content type text/html | 56 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with method GET | 81 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of endpoints with method POST | 18 % |
| Info | Informational | https://stratflow-app-production.up.railway.app | Count of total endpoints | 16    |
| Info | Informational | https://stratflow-app-production.up.railway.app | Percentage of slow responses | 98 % |




## Alerts

| Name | Risk Level | Number of Instances |
| --- | --- | --- |
| Content Security Policy (CSP) Header Not Set | Medium | 1 |
| Permissions Policy Header Not Set | Low | 1 |
| Strict-Transport-Security Header Not Set | Low | 1 |
| Timestamp Disclosure - Unix | Low | 5 |
| Authentication Request Identified | Informational | 1 |
| Base64 Disclosure | Informational | 7 |
| Non-Storable Content | Informational | Systemic |
| Sec-Fetch-Dest Header is Missing | Informational | 4 |
| Sec-Fetch-Mode Header is Missing | Informational | 4 |
| Sec-Fetch-Site Header is Missing | Informational | 4 |
| Sec-Fetch-User Header is Missing | Informational | 4 |
| Session Management Response Identified | Informational | 3 |
| Storable and Cacheable Content | Informational | 2 |
| User Controllable HTML Element Attribute (Potential XSS) | Informational | 2 |




## Alert Detail



### [ Content Security Policy (CSP) Header Not Set ](https://www.zaproxy.org/docs/alerts/10038/)



##### Medium (High)

### Description

Content Security Policy (CSP) is an added layer of security that helps to detect and mitigate certain types of attacks, including Cross Site Scripting (XSS) and data injection attacks. These attacks are used for everything from data theft to site defacement or distribution of malware. CSP provides a set of standard HTTP headers that allow website owners to declare approved sources of content that browsers should be allowed to load on that page — covered types are JavaScript, CSS, HTML frames, fonts, images and embeddable objects such as Java applets, ActiveX, audio and video files.

* URL: https://stratflow-app-production.up.railway.app/checkout
  * Node Name: `https://stratflow-app-production.up.railway.app/checkout ()(_csrf_token,price_id)`
  * Method: `POST`
  * Parameter: ``
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 1

### Solution

Ensure that your web server, application server, load balancer, etc. is configured to set the Content-Security-Policy header.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CSP)
* [ https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html ](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html)
* [ https://www.w3.org/TR/CSP/ ](https://www.w3.org/TR/CSP/)
* [ https://w3c.github.io/webappsec-csp/ ](https://w3c.github.io/webappsec-csp/)
* [ https://web.dev/articles/csp ](https://web.dev/articles/csp)
* [ https://caniuse.com/#feat=contentsecuritypolicy ](https://caniuse.com/#feat=contentsecuritypolicy)
* [ https://content-security-policy.com/ ](https://content-security-policy.com/)


#### CWE Id: [ 693 ](https://cwe.mitre.org/data/definitions/693.html)


#### WASC Id: 15

#### Source ID: 3

### [ Permissions Policy Header Not Set ](https://www.zaproxy.org/docs/alerts/10063/)



##### Low (Medium)

### Description

Permissions Policy Header is an added layer of security that helps to restrict from unauthorized access or usage of browser/client features by web resources. This policy ensures the user privacy by limiting or specifying the features of the browsers can be used by the web resources. Permissions Policy provides a set of standard HTTP headers that allow website owners to limit which features of browsers can be used by the page such as camera, microphone, location, full screen etc.

* URL: https://stratflow-app-production.up.railway.app/checkout
  * Node Name: `https://stratflow-app-production.up.railway.app/checkout ()(_csrf_token,price_id)`
  * Method: `POST`
  * Parameter: ``
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 1

### Solution

Ensure that your web server, application server, load balancer, etc. is configured to set the Permissions-Policy header.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Permissions-Policy ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Permissions-Policy)
* [ https://developer.chrome.com/blog/feature-policy/ ](https://developer.chrome.com/blog/feature-policy/)
* [ https://scotthelme.co.uk/a-new-security-header-feature-policy/ ](https://scotthelme.co.uk/a-new-security-header-feature-policy/)
* [ https://w3c.github.io/webappsec-feature-policy/ ](https://w3c.github.io/webappsec-feature-policy/)
* [ https://www.smashingmagazine.com/2018/12/feature-policy/ ](https://www.smashingmagazine.com/2018/12/feature-policy/)


#### CWE Id: [ 693 ](https://cwe.mitre.org/data/definitions/693.html)


#### WASC Id: 15

#### Source ID: 3

### [ Strict-Transport-Security Header Not Set ](https://www.zaproxy.org/docs/alerts/10035/)



##### Low (High)

### Description

HTTP Strict Transport Security (HSTS) is a web security policy mechanism whereby a web server declares that complying user agents (such as a web browser) are to interact with it using only secure HTTPS connections (i.e. HTTP layered over TLS/SSL). HSTS is an IETF standards track protocol and is specified in RFC 6797.

* URL: https://stratflow-app-production.up.railway.app/checkout
  * Node Name: `https://stratflow-app-production.up.railway.app/checkout ()(_csrf_token,price_id)`
  * Method: `POST`
  * Parameter: ``
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 1

### Solution

Ensure that your web server, application server, load balancer, etc. is configured to enforce Strict-Transport-Security.

### Reference


* [ https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Strict_Transport_Security_Cheat_Sheet.html ](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Strict_Transport_Security_Cheat_Sheet.html)
* [ https://owasp.org/www-community/Security_Headers ](https://owasp.org/www-community/Security_Headers)
* [ https://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security ](https://en.wikipedia.org/wiki/HTTP_Strict_Transport_Security)
* [ https://caniuse.com/stricttransportsecurity ](https://caniuse.com/stricttransportsecurity)
* [ https://datatracker.ietf.org/doc/html/rfc6797 ](https://datatracker.ietf.org/doc/html/rfc6797)


#### CWE Id: [ 319 ](https://cwe.mitre.org/data/definitions/319.html)


#### WASC Id: 15

#### Source ID: 3

### [ Timestamp Disclosure - Unix ](https://www.zaproxy.org/docs/alerts/10096/)



##### Low (Low)

### Description

A timestamp was disclosed by the application/web server. - Unix

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1776047033`
  * Other Info: `1776047033, which evaluates to: 2026-04-13 02:23:53.`
* URL: https://stratflow-app-production.up.railway.app/
  * Node Name: `https://stratflow-app-production.up.railway.app/`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1776047033`
  * Other Info: `1776047033, which evaluates to: 2026-04-13 02:23:53.`
* URL: https://stratflow-app-production.up.railway.app/forgot-password
  * Node Name: `https://stratflow-app-production.up.railway.app/forgot-password`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1776047033`
  * Other Info: `1776047033, which evaluates to: 2026-04-13 02:23:53.`
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1776047033`
  * Other Info: `1776047033, which evaluates to: 2026-04-13 02:23:53.`
* URL: https://stratflow-app-production.up.railway.app/pricing
  * Node Name: `https://stratflow-app-production.up.railway.app/pricing`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `1776047033`
  * Other Info: `1776047033, which evaluates to: 2026-04-13 02:23:53.`


Instances: 5

### Solution

Manually confirm that the timestamp data is not sensitive, and that the data cannot be aggregated to disclose exploitable patterns.

### Reference


* [ https://cwe.mitre.org/data/definitions/200.html ](https://cwe.mitre.org/data/definitions/200.html)


#### CWE Id: [ 497 ](https://cwe.mitre.org/data/definitions/497.html)


#### WASC Id: 13

#### Source ID: 3

### [ Authentication Request Identified ](https://www.zaproxy.org/docs/alerts/10111/)



##### Informational (High)

### Description

The given request has been identified as an authentication request. The 'Other Info' field contains a set of key=value lines which identify any relevant fields. If the request is in a context which has an Authentication Method set to "Auto-Detect" then this rule will change the authentication to match the request identified.

* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login ()(_csrf_token,email,password)`
  * Method: `POST`
  * Parameter: `email`
  * Attack: ``
  * Evidence: `password`
  * Other Info: `userParam=email
userValue=zaproxy@example.com
passwordParam=password
referer=https://stratflow-app-production.up.railway.app/login
csrfToken=_csrf_token`


Instances: 1

### Solution

This is an informational alert rather than a vulnerability and so there is nothing to fix.

### Reference


* [ https://www.zaproxy.org/docs/desktop/addons/authentication-helper/auth-req-id/ ](https://www.zaproxy.org/docs/desktop/addons/authentication-helper/auth-req-id/)



#### Source ID: 3

### [ Base64 Disclosure ](https://www.zaproxy.org/docs/alerts/10094/)



##### Informational (Medium)

### Description

Base64 encoded data was disclosed by the application/web server. Note: in the interests of performance not all base64 strings in the response were analyzed individually, the entire response should be looked at by the analyst/security team/developer(s).

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `price_1TJnXrQe7EER7a8B7XzuDRNZ`
  * Other Info: `���{�S&u�A��������5`
* URL: https://stratflow-app-production.up.railway.app/
  * Node Name: `https://stratflow-app-production.up.railway.app/`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `12BkwgK9pfrqMr6CVB2psY-83n1TETWG-B4CG0Ne4vYuISW1`
  * Other Info: `�`d�����2��T�����}S5��C^��.!%�`
* URL: https://stratflow-app-production.up.railway.app/
  * Node Name: `https://stratflow-app-production.up.railway.app/`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `2Ch99f15JtPRGi5yrQNd-gh90f1WzMbFTMNkRHKIzeMV3lDR`
  * Other Info: `�(}��y&��.r�]�}��V���L�dDr����P�`
* URL: https://stratflow-app-production.up.railway.app/
  * Node Name: `https://stratflow-app-production.up.railway.app/`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `price_1TJnXrQe7EER7a8B7XzuDRNZ`
  * Other Info: `���{�S&u�A��������5`
* URL: https://stratflow-app-production.up.railway.app/pricing
  * Node Name: `https://stratflow-app-production.up.railway.app/pricing`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `price_1TJnXrQe7EER7a8B7XzuDRNZ`
  * Other Info: `���{�S&u�A��������5`
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `KqmwSZeYjh85q4EoCL1TMy9yP20MfORHoajEvfN2XAFbMS2c`
  * Other Info: `*��I���9��(�S3/r?m|�G��Ľ�v\[1-�`
* URL: https://stratflow-app-production.up.railway.app/checkout
  * Node Name: `https://stratflow-app-production.up.railway.app/checkout ()(_csrf_token,price_id)`
  * Method: `POST`
  * Parameter: ``
  * Attack: ``
  * Evidence: `com/c/pay/cs_test_a1CBI0uHfULHj6SzfGdSknBi0z2Uhwuh971K54XSwkiRw46A3ufRhJYKdY`
  * Other Info: `r��s�Z��,�׬���4�w�,x�K7�u)'-3�Hp�{Ԯx],$�8�
�}I`�X`


Instances: 7

### Solution

Manually confirm that the Base64 data does not leak sensitive information, and that the data cannot be aggregated/used to exploit other vulnerabilities.

### Reference


* [ https://projects.webappsec.org/w/page/13246936/Information%20Leakage ](https://projects.webappsec.org/w/page/13246936/Information%20Leakage)


#### CWE Id: [ 319 ](https://cwe.mitre.org/data/definitions/319.html)


#### WASC Id: 13

#### Source ID: 3

### [ Non-Storable Content ](https://www.zaproxy.org/docs/alerts/10049/)



##### Informational (Medium)

### Description

The response contents are not storable by caching components such as proxy servers. If the response does not contain sensitive, personal or user-specific information, it may benefit from being stored and cached, to improve performance.

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `no-store`
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `no-store`
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/pricing
  * Node Name: `https://stratflow-app-production.up.railway.app/pricing`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `no-store`
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `no-store`
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/sitemap.xml
  * Node Name: `https://stratflow-app-production.up.railway.app/sitemap.xml`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `no-store`
  * Other Info: ``

Instances: Systemic


### Solution

The content may be marked as storable by ensuring that the following conditions are satisfied:
The request method must be understood by the cache and defined as being cacheable ("GET", "HEAD", and "POST" are currently defined as cacheable)
The response status code must be understood by the cache (one of the 1XX, 2XX, 3XX, 4XX, or 5XX response classes are generally understood)
The "no-store" cache directive must not appear in the request or response header fields
For caching by "shared" caches such as "proxy" caches, the "private" response directive must not appear in the response
For caching by "shared" caches such as "proxy" caches, the "Authorization" header field must not appear in the request, unless the response explicitly allows it (using one of the "must-revalidate", "public", or "s-maxage" Cache-Control response directives)
In addition to the conditions above, at least one of the following conditions must also be satisfied by the response:
It must contain an "Expires" header field
It must contain a "max-age" response directive
For "shared" caches such as "proxy" caches, it must contain a "s-maxage" response directive
It must contain a "Cache Control Extension" that allows it to be cached
It must have a status code that is defined as cacheable by default (200, 203, 204, 206, 300, 301, 404, 405, 410, 414, 501).

### Reference


* [ https://datatracker.ietf.org/doc/html/rfc7234 ](https://datatracker.ietf.org/doc/html/rfc7234)
* [ https://datatracker.ietf.org/doc/html/rfc7231 ](https://datatracker.ietf.org/doc/html/rfc7231)
* [ https://www.w3.org/Protocols/rfc2616/rfc2616-sec13.html ](https://www.w3.org/Protocols/rfc2616/rfc2616-sec13.html)


#### CWE Id: [ 524 ](https://cwe.mitre.org/data/definitions/524.html)


#### WASC Id: 13

#### Source ID: 3

### [ Sec-Fetch-Dest Header is Missing ](https://www.zaproxy.org/docs/alerts/90005/)



##### Informational (High)

### Description

Specifies how and where the data would be used. For instance, if the value is audio, then the requested resource must be audio data and not any other type of resource.

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Dest`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Dest`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Dest`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/sitemap.xml
  * Node Name: `https://stratflow-app-production.up.railway.app/sitemap.xml`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Dest`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 4

### Solution

Ensure that Sec-Fetch-Dest header is included in request headers.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Dest ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Dest)


#### CWE Id: [ 352 ](https://cwe.mitre.org/data/definitions/352.html)


#### WASC Id: 9

#### Source ID: 3

### [ Sec-Fetch-Mode Header is Missing ](https://www.zaproxy.org/docs/alerts/90005/)



##### Informational (High)

### Description

Allows to differentiate between requests for navigating between HTML pages and requests for loading resources like images, audio etc.

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Mode`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Mode`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Mode`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/sitemap.xml
  * Node Name: `https://stratflow-app-production.up.railway.app/sitemap.xml`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Mode`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 4

### Solution

Ensure that Sec-Fetch-Mode header is included in request headers.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Mode ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Mode)


#### CWE Id: [ 352 ](https://cwe.mitre.org/data/definitions/352.html)


#### WASC Id: 9

#### Source ID: 3

### [ Sec-Fetch-Site Header is Missing ](https://www.zaproxy.org/docs/alerts/90005/)



##### Informational (High)

### Description

Specifies the relationship between request initiator's origin and target's origin.

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Site`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Site`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Site`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/sitemap.xml
  * Node Name: `https://stratflow-app-production.up.railway.app/sitemap.xml`
  * Method: `GET`
  * Parameter: `Sec-Fetch-Site`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 4

### Solution

Ensure that Sec-Fetch-Site header is included in request headers.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Site ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-Site)


#### CWE Id: [ 352 ](https://cwe.mitre.org/data/definitions/352.html)


#### WASC Id: 9

#### Source ID: 3

### [ Sec-Fetch-User Header is Missing ](https://www.zaproxy.org/docs/alerts/90005/)



##### Informational (High)

### Description

Specifies if a navigation request was initiated by a user.

* URL: https://stratflow-app-production.up.railway.app
  * Node Name: `https://stratflow-app-production.up.railway.app`
  * Method: `GET`
  * Parameter: `Sec-Fetch-User`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login`
  * Method: `GET`
  * Parameter: `Sec-Fetch-User`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: `Sec-Fetch-User`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/sitemap.xml
  * Node Name: `https://stratflow-app-production.up.railway.app/sitemap.xml`
  * Method: `GET`
  * Parameter: `Sec-Fetch-User`
  * Attack: ``
  * Evidence: ``
  * Other Info: ``


Instances: 4

### Solution

Ensure that Sec-Fetch-User header is included in user initiated requests.

### Reference


* [ https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-User ](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Sec-Fetch-User)


#### CWE Id: [ 352 ](https://cwe.mitre.org/data/definitions/352.html)


#### WASC Id: 9

#### Source ID: 3

### [ Session Management Response Identified ](https://www.zaproxy.org/docs/alerts/10112/)



##### Informational (Medium)

### Description

The given response has been identified as containing a session management token. The 'Other Info' field contains a set of header tokens that can be used in the Header Based Session Management Method. If the request is in a context which has a Session Management Method set to "Auto-Detect" then this rule will change the session management to use the tokens identified.

* URL: https://stratflow-app-production.up.railway.app/
  * Node Name: `https://stratflow-app-production.up.railway.app/`
  * Method: `GET`
  * Parameter: `__Host-stratflow_session`
  * Attack: ``
  * Evidence: `__Host-stratflow_session`
  * Other Info: `cookie:__Host-stratflow_session`
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: `__Host-stratflow_session`
  * Attack: ``
  * Evidence: `__Host-stratflow_session`
  * Other Info: `cookie:__Host-stratflow_session`
* URL: https://stratflow-app-production.up.railway.app/robots.txt
  * Node Name: `https://stratflow-app-production.up.railway.app/robots.txt`
  * Method: `GET`
  * Parameter: `__Host-stratflow_session`
  * Attack: ``
  * Evidence: `__Host-stratflow_session`
  * Other Info: `cookie:__Host-stratflow_session`


Instances: 3

### Solution

This is an informational alert rather than a vulnerability and so there is nothing to fix.

### Reference


* [ https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/ ](https://www.zaproxy.org/docs/desktop/addons/authentication-helper/session-mgmt-id/)



#### Source ID: 3

### [ Storable and Cacheable Content ](https://www.zaproxy.org/docs/alerts/10049/)



##### Informational (Medium)

### Description

The response contents are storable by caching components such as proxy servers, and may be retrieved directly from the cache, rather than from the origin server by the caching servers, in response to similar requests from other users. If the response data is sensitive, personal or user-specific, this may result in sensitive information being leaked. In some cases, this may even result in a user gaining complete control of the session of another user, depending on the configuration of the caching components in use in their environment. This is primarily an issue where "shared" caching servers such as "proxy" caches are configured on the local network. This configuration is typically found in corporate or educational environments, for instance.

* URL: https://stratflow-app-production.up.railway.app/assets/js/auth.js%3Fv=1776047033
  * Node Name: `https://stratflow-app-production.up.railway.app/assets/js/auth.js (v)`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `max-age=86400`
  * Other Info: ``
* URL: https://stratflow-app-production.up.railway.app/favicon.svg
  * Node Name: `https://stratflow-app-production.up.railway.app/favicon.svg`
  * Method: `GET`
  * Parameter: ``
  * Attack: ``
  * Evidence: `max-age=86400`
  * Other Info: ``


Instances: 2

### Solution

Validate that the response does not contain sensitive, personal or user-specific information. If it does, consider the use of the following HTTP response headers, to limit, or prevent the content being stored and retrieved from the cache by another user:
Cache-Control: no-cache, no-store, must-revalidate, private
Pragma: no-cache
Expires: 0
This configuration directs both HTTP 1.0 and HTTP 1.1 compliant caching servers to not store the response, and to not retrieve the response (without validation) from the cache, in response to a similar request.

### Reference


* [ https://datatracker.ietf.org/doc/html/rfc7234 ](https://datatracker.ietf.org/doc/html/rfc7234)
* [ https://datatracker.ietf.org/doc/html/rfc7231 ](https://datatracker.ietf.org/doc/html/rfc7231)
* [ https://www.w3.org/Protocols/rfc2616/rfc2616-sec13.html ](https://www.w3.org/Protocols/rfc2616/rfc2616-sec13.html)


#### CWE Id: [ 524 ](https://cwe.mitre.org/data/definitions/524.html)


#### WASC Id: 13

#### Source ID: 3

### [ User Controllable HTML Element Attribute (Potential XSS) ](https://www.zaproxy.org/docs/alerts/10031/)



##### Informational (Low)

### Description

This check looks at user-supplied input in query string parameters and POST data to identify where certain HTML attribute values might be controlled. This provides hot-spot detection for XSS (cross-site scripting) that will require further review by a security analyst to determine exploitability.

* URL: https://stratflow-app-production.up.railway.app/forgot-password
  * Node Name: `https://stratflow-app-production.up.railway.app/forgot-password ()(_csrf_token,email)`
  * Method: `POST`
  * Parameter: `_csrf_token`
  * Attack: ``
  * Evidence: ``
  * Other Info: `User-controlled HTML attribute values were found. Try injecting special characters to see if XSS might be possible. The page at the following URL:

https://stratflow-app-production.up.railway.app/forgot-password

appears to include user input in:
a(n) [input] tag [value] attribute

The user input found was:
_csrf_token=3db56acbb576b4ac2e408eabc8540f2440a07117f936c78124279b53f305b09f

The user-controlled value was:
3db56acbb576b4ac2e408eabc8540f2440a07117f936c78124279b53f305b09f`
* URL: https://stratflow-app-production.up.railway.app/login
  * Node Name: `https://stratflow-app-production.up.railway.app/login ()(_csrf_token,email,password)`
  * Method: `POST`
  * Parameter: `_csrf_token`
  * Attack: ``
  * Evidence: ``
  * Other Info: `User-controlled HTML attribute values were found. Try injecting special characters to see if XSS might be possible. The page at the following URL:

https://stratflow-app-production.up.railway.app/login

appears to include user input in:
a(n) [input] tag [value] attribute

The user input found was:
_csrf_token=3db56acbb576b4ac2e408eabc8540f2440a07117f936c78124279b53f305b09f

The user-controlled value was:
3db56acbb576b4ac2e408eabc8540f2440a07117f936c78124279b53f305b09f`


Instances: 2

### Solution

Validate all input and sanitize output it before writing to any HTML attributes.

### Reference


* [ https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html ](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html)


#### CWE Id: [ 20 ](https://cwe.mitre.org/data/definitions/20.html)


#### WASC Id: 20

#### Source ID: 3


