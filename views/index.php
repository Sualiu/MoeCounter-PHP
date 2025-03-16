<?php
// Define variables (you'll likely pass these from a configuration or controller)
$site = isset($site) ? $site : 'https://example.com';
$ga_id = isset($ga_id) ? $ga_id : null;
$themeList = isset($themeList) ? $themeList : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Moe Counter!</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="<?php echo $site; ?>/assets/favicon.png">
    <link rel="stylesheet" href="https://fastly.jsdelivr.net/npm/normalize.css">
    <link rel="stylesheet" href="https://fastly.jsdelivr.net/npm/bamboo.css">
    <link rel="stylesheet/less" href="<?php echo $site; ?>/assets/style.less">
    <script src="https://fastly.jsdelivr.net/npm/less"></script>

    <?php if ($ga_id): ?>
    <!-- Global site tag (gtag.js) - Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $ga_id; ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo $ga_id; ?>');

        function _evt_push(type, category, label) {
            gtag('event', type, {
                'event_category': category,
                'event_label': label
            });
        }
    </script>
    <?php endif; ?>

    <script>
        var __global_data = { site: "<?php echo $site; ?>" };
    </script>
</head>
<body>
    <h1 id="main_title">
        <i>Moe Counter!</i>
    </h1>

    <h3>How to use</h3>
    <p>Set a unique id for your counter, replace <code>:name</code> in the url, That's it!</p>

    <h5>SVG address</h5>
    <code><?php echo $site; ?>/@:name</code>

    <h5>Img tag</h5>
    <code>&lt;img src="<?php echo $site; ?>/@:name" alt=":name" /&gt;</code>

    <h5>Markdown</h5>
    <code>![:name](<?php echo $site; ?>/@:name)</code>

    <h5>e.g.</h5>
    <img src="<?php echo $site; ?>/@index" alt="Moe Counter!">

    <details id="themes">
        <summary id="more_theme" onclick="_evt_push('click', 'normal', 'more_theme')">
            <h3>More theme✨</h3>
        </summary>
        <p>Just use the query parameters <code>theme</code>, like this: <code><?php echo $site; ?>/@:name?theme=moebooru</code></p>
        <?php foreach (array_keys($themeList) as $theme): ?>
            <div class="item" data-theme="<?php echo $theme; ?>">
                <h5><?php echo $theme; ?></h5>
                <img data-src="<?php echo $site; ?>/@demo?theme=<?php echo $theme; ?>" alt="<?php echo $theme; ?>">
            </div>
        <?php endforeach; ?>
    </details>

    <h3>Credits</h3>
    <ul>
        <li><a href="https://glitch.com/" target="_blank" rel="nofollow noopener noreferrer">Glitch</a></li>
        <li><a href="https://space.bilibili.com/703007996" target="_blank" rel="noopener noreferrer" title="A-SOUL_Official">A-SOUL</a></li>
        <li><a href="https://github.com/moebooru/moebooru" target="_blank" rel="nofollow noopener noreferrer">moebooru</a></li>
        <li><a rel="noopener noreferrer" href="javascript:alert('!!! NSFW LINK !!!\nPlease enter the url manually')">gelbooru.com</a> NSFW</li>
        <li><a href="https://icons8.com/icon/80355/star" target="_blank" rel="nofollow noopener noreferrer">Icons8</a></li>
        <span><i>And all booru site...</i></span>
    </ul>

    <h3>Tool</h3>
    <div class="tool">
        <table>
            <thead>
                <tr>
                    <th>Param</th>
                    <th>Description</th>
                    <th>Value</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>name</code></td>
                    <td>Unique counter name</td>
                    <td><input type="text" id="name" placeholder=":name"></td>
                </tr>
                <tr>
                    <td><code>theme</code></td>
                    <td>Select a counter image theme, default is <code>moebooru</code></td>
                    <td>
                        <select id="theme">
                            <option value="random" selected>* random</option>
<?php foreach (array_keys($themeList) as $theme): ?>
                            <option value="<?php echo $theme; ?>"><?php echo $theme; ?></option>
<?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><code>padding</code></td>
                    <td>Set the minimum length, between 1-16, default is <code>7</code></td>
                    <td><input type="number" id="padding" value="7" min="1" max="32" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></td>
                </tr>
                <tr>
                    <td><code>offset</code></td>
                    <td>Set the offset pixel value, between -500-500, default is <code>0</code></td>
                    <td><input type="number" id="offset" value="0" min="-500" max="500" step="1" oninput="this.value = this.value.replace(/[^0-9|\-]/g, '')"></td>
                </tr>
                <tr>
                    <td><code>scale</code></td>
                    <td>Set the image scale, between 0.1-2, default is <code>1</code></td>
                    <td><input type="number" id="scale" value="1" min="0.1" max="2" step="0.1" oninput="this.value = this.value.replace(/[^0-9|\.]/g, '')"></td>
                </tr>
                <tr>
                    <td><code>align</code></td>
                    <td>Set the image align, Enum top/center/bottom, default is <code>top</code></td>
                    <td>
                        <select id="align" name="align">
                            <option value="top" selected>top</option>
                            <option value="center">center</option>
                            <option value="bottom">bottom</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><code>pixelated</code></td>
                    <td>Enable pixelated mode, Enum 0/1, default is <code>1</code></td>
                    <td>
                        <input type="checkbox" id="pixelated" role="switch" checked>
                        <label for="pixelated"><span></span></label>
                    </td>
                </tr>
                <tr>
                    <td><code>darkmode</code></td>
                    <td>Enable dark mode, Enum 0/1/auto, default is <code>auto</code></td>
                    <td>
                        <select id="darkmode" name="darkmode">
                            <option value="auto" selected>auto</option>
                            <option value="1">yes</option>
                            <option value="0">no</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td colspan="3">
                        <h4 class="caption">Unusual Options</h4>
                    </td>
                </tr>
                <tr>
                    <td><code>num</code></td>
                    <td>Set counter display number, 0 for disable, default is <code>0</code></td>
                    <td><input type="number" id="num" value="0" min="0" max="1e15" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></td>
                </tr>
                <tr>
                    <td><code>prefix</code></td>
                    <td>Set the prefix number, empty for disable</td>
                    <td><input type="number" id="prefix" value="" min="0" max="999999" step="1" oninput="this.value = this.value.replace(/[^0-9]/g, '')"></td>
                </tr>
            </tbody>
        </table>

        <button id="get" onclick="_evt_push('click', 'normal', 'get_counter')">Generate</button>

        <div>
            <code id="code"></code>
            <img id="result">
        </div>
    </div>

    <p class="github">
        <a href="https://github.com/journey-ad/Moe-Counter" target="_blank" rel="noopener noreferrer onclick="_evt_push('click', 'normal', 'go_github')">source code</a>
    </p>

    <div class="back-to-top"></div>

    <script async src="https://fastly.jsdelivr.net/npm/party-js@2/bundle/party.min.js"></script>
    <script async src="<?php echo $site; ?>/assets/script.js"></script>
</body>
</html>