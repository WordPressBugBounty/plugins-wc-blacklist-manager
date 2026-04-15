(function(window, document, $) {
	'use strict';

	var wcBmCachedPayload = null;
	var wcBmCachedPayloadJson = '';
	var wcBmPayloadPromise = null;
	var wcBmPrimeBound = false;
	var wcBmFetchPatched = false;
	var wcBmXhrPatched = false;

	function wcBmReadCookie(name) {
		var escaped = name.replace(/[-[\]/{}()*+?.\\^$|]/g, '\\$&');
		var match = document.cookie.match(new RegExp('(?:^|; )' + escaped + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : '';
	}

	function wcBmSetCookie(name, value, seconds) {
		try {
			var expires = '';
			if (seconds && seconds > 0) {
				var date = new Date();
				date.setTime(date.getTime() + (seconds * 1000));
				expires = '; expires=' + date.toUTCString();
			}

			var secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
			document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/' + secure + '; SameSite=Lax';
			return true;
		} catch (e) {
			return false;
		}
	}

	function wcBmRandomId() {
		try {
			if (window.crypto && window.crypto.getRandomValues) {
				var bytes = new Uint8Array(16);
				window.crypto.getRandomValues(bytes);
				return Array.from(bytes).map(function(b) {
					return b.toString(16).padStart(2, '0');
				}).join('');
			}
		} catch (e) {}

		return Math.random().toString(36).substring(2) + Date.now().toString(36);
	}

	function wcBmGetCanvasFingerprint(flags) {
		try {
			var canvas = document.createElement('canvas');
			var ctx = canvas.getContext('2d');

			if (!ctx) {
				flags.push('canvas_unavailable');
				return '';
			}

			ctx.textBaseline = 'top';
			ctx.font = '14px Arial';
			ctx.fillText('wc-bm-fp', 2, 2);

			return canvas.toDataURL();
		} catch (e) {
			flags.push('canvas_error');
			return '';
		}
	}

	function wcBmCollectDeviceFingerprint(flags) {
		var timezone = '';

		try {
			timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e) {
			timezone = '';
			flags.push('timezone_unavailable');
		}

		return {
			ua: navigator.userAgent || '',
			lang: navigator.language || '',
			tz: timezone,
			screen: (window.screen ? window.screen.width : 0) + 'x' + (window.screen ? window.screen.height : 0),
			color_depth: window.screen ? (window.screen.colorDepth || 0) : 0,
			pixel_ratio: window.devicePixelRatio || 1,
			platform: navigator.platform || '',
			cores: navigator.hardwareConcurrency || 0,
			memory: navigator.deviceMemory || 0,
			canvas: wcBmGetCanvasFingerprint(flags)
		};
	}

	function wcBmNormalizeFingerprint(fp) {
		return {
			ua: String(fp.ua || ''),
			lang: String(fp.lang || '').toLowerCase(),
			tz: String(fp.tz || ''),
			screen: String(fp.screen || ''),
			color_depth: parseInt(fp.color_depth || 0, 10) || 0,
			pixel_ratio: String(fp.pixel_ratio || 1),
			platform: String(fp.platform || ''),
			cores: parseInt(fp.cores || 0, 10) || 0,
			memory: parseInt(fp.memory || 0, 10) || 0,
			canvas: String(fp.canvas || '')
		};
	}

	async function wcBmSha256(input) {
		if (!window.crypto || !window.crypto.subtle || typeof TextEncoder === 'undefined') {
			return '';
		}

		var encoder = new TextEncoder();
		var data = encoder.encode(input);
		var hashBuffer = await window.crypto.subtle.digest('SHA-256', data);
		var hashArray = Array.from(new Uint8Array(hashBuffer));

		return hashArray.map(function(b) {
			return b.toString(16).padStart(2, '0');
		}).join('');
	}

	function wcBmGetBrowserId(flags) {
		var browserId = '';
		var cookieId = wcBmReadCookie('wc_bm_bid');
		var created = false;

		try {
			browserId = window.localStorage.getItem('wc_bm_bid') || '';
		} catch (e) {
			flags.push('localstorage_unavailable');
		}

		if (!browserId && cookieId) {
			browserId = cookieId;
			flags.push('browser_id_from_cookie');
		}

		if (!browserId) {
			browserId = wcBmRandomId();
			created = true;
		}

		try {
			window.localStorage.setItem('wc_bm_bid', browserId);
		} catch (e) {
			flags.push('localstorage_write_failed');
		}

		if (!wcBmSetCookie('wc_bm_bid', browserId, 365 * 24 * 60 * 60)) {
			flags.push('browser_cookie_write_failed');
		}

		if (created) {
			flags.push('browser_id_created');
		}

		return browserId;
	}

	function wcBmGetSessionId(flags) {
		var sid = '';

		try {
			sid = window.sessionStorage.getItem('wc_bm_sid') || '';

			if (!sid) {
				sid = wcBmRandomId();
				window.sessionStorage.setItem('wc_bm_sid', sid);
				flags.push('session_id_created');
			}
		} catch (e) {
			sid = '';
			flags.push('sessionstorage_unavailable');
		}

		return sid;
	}

	function wcBmCountFingerprintSignals(fp) {
		var count = 0;

		if (fp.ua) { count++; }
		if (fp.lang) { count++; }
		if (fp.tz) { count++; }
		if (fp.screen && fp.screen !== '0x0') { count++; }
		if (fp.color_depth) { count++; }
		if (fp.pixel_ratio) { count++; }
		if (fp.platform) { count++; }
		if (fp.cores) { count++; }
		if (fp.memory) { count++; }
		if (fp.canvas) { count++; }

		return count;
	}

	function wcBmGetConfidence(browserId, fpHash, sessionId, flags, signalCount) {
		if (!browserId || !fpHash) {
			return 'low';
		}

		if (signalCount < 5) {
			flags.push('low_entropy_fp');
			return 'low';
		}

		if (!sessionId) {
			return 'medium';
		}

		if (
			flags.indexOf('localstorage_unavailable') !== -1 ||
			flags.indexOf('sessionstorage_unavailable') !== -1
		) {
			return 'medium';
		}

		return 'high';
	}

	async function wcBmBuildDevicePayload() {
		var flags = [];
		var seed = wcBmReadCookie('wc_bm_did_seed');

		if (!seed) {
			flags.push('missing_seed_cookie');
		}

		var browserId = wcBmGetBrowserId(flags);
		var sessionId = wcBmGetSessionId(flags);
		var fingerprint = wcBmNormalizeFingerprint(wcBmCollectDeviceFingerprint(flags));
		var signalCount = wcBmCountFingerprintSignals(fingerprint);

		if (signalCount < 5) {
			flags.push('low_entropy_fp');
		}

		var fpHash = await wcBmSha256(JSON.stringify({
			version: 'v1',
			fingerprint: fingerprint
		}));

		var deviceId = await wcBmSha256(JSON.stringify({
			version: 'v1',
			browser_id: browserId,
			fp_hash: fpHash,
			seed: seed || ''
		}));

		var confidence = wcBmGetConfidence(browserId, fpHash, sessionId, flags, signalCount);

		flags = Array.from(new Set(flags));

		return {
			version: 'v1',
			device_id: deviceId,
			browser_id: browserId,
			fp_hash: fpHash,
			session_id: sessionId,
			confidence: confidence,
			flags: flags
		};
	}

	function wcBmStoreCachedPayload(payload) {
		wcBmCachedPayload = payload || null;
		wcBmCachedPayloadJson = payload ? JSON.stringify(payload) : '';
	}

	function wcBmGetHiddenField() {
		var $field = $('input[name="wc_blacklist_device"]');

		if (!$field.length) {
			$field = $('<input>', {
				type: 'hidden',
				name: 'wc_blacklist_device'
			});

			var $checkoutForm = $('form.checkout');

			if ($checkoutForm.length) {
				$checkoutForm.append($field);
			}
		}

		return $field;
	}

	function wcBmInjectCachedPayloadIntoClassicCheckout() {
		if (!wcBmCachedPayloadJson) {
			return false;
		}

		var $field = wcBmGetHiddenField();

		if (!$field.length) {
			return false;
		}

		$field.val(wcBmCachedPayloadJson);

		return true;
	}

	function wcBmEnsurePayload(forceRefresh) {
		if (!forceRefresh && wcBmCachedPayloadJson) {
			return Promise.resolve(wcBmCachedPayload);
		}

		if (!forceRefresh && wcBmPayloadPromise) {
			return wcBmPayloadPromise;
		}

		wcBmPayloadPromise = wcBmBuildDevicePayload()
			.then(function(payload) {
				wcBmStoreCachedPayload(payload);
				return payload;
			})
			.catch(function() {
				wcBmStoreCachedPayload(null);
				return null;
			})
			.finally(function() {
				wcBmPayloadPromise = null;
			});

		return wcBmPayloadPromise;
	}

	function wcBmPrimePayload() {
		return wcBmEnsurePayload(false).then(function() {
			wcBmInjectCachedPayloadIntoClassicCheckout();
		});
	}

	function wcBmBindPrimeEvents() {
		if (wcBmPrimeBound) {
			return;
		}

		wcBmPrimeBound = true;

		var once = function() {
			wcBmPrimePayload();
			document.removeEventListener('mousemove', once, true);
			document.removeEventListener('keydown', once, true);
			document.removeEventListener('touchstart', once, true);
			document.removeEventListener('focus', once, true);
			document.removeEventListener('click', once, true);
		};

		document.addEventListener('mousemove', once, true);
		document.addEventListener('keydown', once, true);
		document.addEventListener('touchstart', once, true);
		document.addEventListener('focus', once, true);
		document.addEventListener('click', once, true);

		document.addEventListener('visibilitychange', function() {
			if (document.visibilityState === 'visible') {
				wcBmPrimePayload();
			}
		});
	}

	function wcBmIsStoreApiCheckoutUrl(url) {
		if (!url || typeof url !== 'string') {
			return false;
		}

		return /\/wc\/store\/(?:v\d+\/)?checkout(?:\/|$|\?)/i.test(url);
	}

	function wcBmCloneHeadersObject(headers) {
		var out = {};

		if (!headers) {
			return out;
		}

		if (headers instanceof Headers) {
			headers.forEach(function(value, key) {
				out[key] = value;
			});
			return out;
		}

		if (Array.isArray(headers)) {
			headers.forEach(function(pair) {
				if (pair && pair.length >= 2) {
					out[pair[0]] = pair[1];
				}
			});
			return out;
		}

		if (typeof headers === 'object') {
			Object.keys(headers).forEach(function(key) {
				out[key] = headers[key];
			});
		}

		return out;
	}

	function wcBmLooksLikeJsonContentType(contentType) {
		return typeof contentType === 'string' && contentType.toLowerCase().indexOf('application/json') !== -1;
	}

	function wcBmMergePayloadIntoRequestBody(bodyText, payload) {
		var decoded = {};

		if (bodyText) {
			try {
				decoded = JSON.parse(bodyText);
			} catch (e) {
				return bodyText;
			}
		}

		if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) {
			decoded = {};
		}

		decoded.extensions = decoded.extensions || {};
		decoded.extensions.wc_blacklist_device = payload;

		return JSON.stringify(decoded);
	}

	async function wcBmPatchFetchForStoreApi() {
		if (wcBmFetchPatched || typeof window.fetch !== 'function') {
			return;
		}

		wcBmFetchPatched = true;

		var originalFetch = window.fetch;

		window.fetch = async function(input, init) {
			try {
				var url = '';

				if (typeof input === 'string') {
					url = input;
				} else if (input && typeof input.url === 'string') {
					url = input.url;
				}

				if (!wcBmIsStoreApiCheckoutUrl(url)) {
					return originalFetch.apply(this, arguments);
				}

				var payload = await wcBmEnsurePayload(false);

				if (!payload || !payload.device_id) {
					return originalFetch.apply(this, arguments);
				}

				var requestInit = init ? Object.assign({}, init) : {};
				var originalRequest = (typeof Request !== 'undefined' && input instanceof Request) ? input : null;

				if (!requestInit.method && originalRequest) {
					requestInit.method = originalRequest.method;
				}

				var method = String(requestInit.method || 'GET').toUpperCase();
				if (method !== 'POST') {
					return originalFetch.apply(this, arguments);
				}

				var headers = wcBmCloneHeadersObject(requestInit.headers || (originalRequest ? originalRequest.headers : null));
				var contentType = headers['Content-Type'] || headers['content-type'] || '';

				if (!requestInit.body && originalRequest) {
					try {
						requestInit.body = await originalRequest.clone().text();
					} catch (e) {
						requestInit.body = null;
					}
				}

				if (!wcBmLooksLikeJsonContentType(contentType) && typeof requestInit.body === 'string' && requestInit.body.charAt(0) === '{') {
					contentType = 'application/json';
					headers['Content-Type'] = 'application/json';
				}

				if (!wcBmLooksLikeJsonContentType(contentType)) {
					return originalFetch.apply(this, arguments);
				}

				requestInit.headers = headers;
				requestInit.body = wcBmMergePayloadIntoRequestBody(requestInit.body || '', payload);

				if (originalRequest) {
					input = url;
				}

				return originalFetch.call(this, input, requestInit);
			} catch (e) {
				return originalFetch.apply(this, arguments);
			}
		};
	}

	function wcBmPatchXhrForStoreApi() {
		if (wcBmXhrPatched || typeof window.XMLHttpRequest === 'undefined') {
			return;
		}

		wcBmXhrPatched = true;

		var OriginalOpen = XMLHttpRequest.prototype.open;
		var OriginalSend = XMLHttpRequest.prototype.send;
		var OriginalSetRequestHeader = XMLHttpRequest.prototype.setRequestHeader;

		XMLHttpRequest.prototype.open = function(method, url) {
			this._wcBmMethod = method;
			this._wcBmUrl = url;
			this._wcBmHeaders = {};
			return OriginalOpen.apply(this, arguments);
		};

		XMLHttpRequest.prototype.setRequestHeader = function(header, value) {
			this._wcBmHeaders = this._wcBmHeaders || {};
			this._wcBmHeaders[header] = value;
			return OriginalSetRequestHeader.apply(this, arguments);
		};

		XMLHttpRequest.prototype.send = function(body) {
			var xhr = this;
			var url = xhr._wcBmUrl || '';
			var method = String(xhr._wcBmMethod || 'GET').toUpperCase();

			if (!wcBmIsStoreApiCheckoutUrl(url) || method !== 'POST') {
				return OriginalSend.apply(xhr, arguments);
			}

			var contentType = '';
			if (xhr._wcBmHeaders) {
				contentType = xhr._wcBmHeaders['Content-Type'] || xhr._wcBmHeaders['content-type'] || '';
			}

			if (!wcBmLooksLikeJsonContentType(contentType) && !(typeof body === 'string' && body.charAt(0) === '{')) {
				return OriginalSend.apply(xhr, arguments);
			}

			wcBmEnsurePayload(false)
				.then(function(payload) {
					if (!payload || !payload.device_id) {
						OriginalSend.call(xhr, body);
						return;
					}

					var newBody = wcBmMergePayloadIntoRequestBody(typeof body === 'string' ? body : '', payload);
					OriginalSend.call(xhr, newBody);
				})
				.catch(function() {
					OriginalSend.call(xhr, body);
				});
		};
	}

	function wcBmSetupClassicCheckout() {
		if (typeof $ === 'undefined' || !$('form.checkout').length) {
			return;
		}

		wcBmPrimePayload();

		$(document.body).on('updated_checkout', function() {
			wcBmEnsurePayload(true).then(function() {
				wcBmInjectCachedPayloadIntoClassicCheckout();
			});
		});

		$(document.body).on('checkout_place_order', function() {
			wcBmInjectCachedPayloadIntoClassicCheckout();
			return true;
		});

		$('form.checkout').on('submit', function() {
			wcBmInjectCachedPayloadIntoClassicCheckout();
		});

		$('form.checkout').on('click', '#place_order', function() {
			wcBmEnsurePayload(true).then(function() {
				wcBmInjectCachedPayloadIntoClassicCheckout();
			});
		});
	}

	async function wcBmGetDevicePayloadForStoreApi() {
		try {
			return await wcBmEnsurePayload(false);
		} catch (e) {
			return null;
		}
	}

	window.wcBmBuildDevicePayload = wcBmBuildDevicePayload;
	window.wcBmGetDevicePayloadForStoreApi = wcBmGetDevicePayloadForStoreApi;

	$(function() {
		wcBmBindPrimeEvents();
		wcBmPatchFetchForStoreApi();
		wcBmPatchXhrForStoreApi();
		wcBmSetupClassicCheckout();
		wcBmPrimePayload();
	});

})(window, document, jQuery);