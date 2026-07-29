<div style="
    margin:0;
    padding:30px 12px;
    background:#f3f5f9;
    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;
">

    <table width="100%" cellspacing="0" cellpadding="0">
        <tr>
            <td align="center">

                <!-- 主卡片 -->
                <table width="560" cellspacing="0" cellpadding="0" style="
                    background:#ffffff;
                    border-radius:20px;
                    overflow:hidden;
                    box-shadow:0 8px 30px rgba(0,0,0,0.08);
                ">

                    <!-- 顶部 -->
                    <tr>
                        <td style="
                            background:linear-gradient(135deg,#3B4F8C,#5D7DFF);
                            padding:26px 34px;
                            color:#ffffff;
                        ">
                            <div style="font-size:22px;font-weight:800;">
                                {{$name}}
                            </div>
                        </td>
                    </tr>

                    <!-- 内容 -->
                    <tr>
                        <td style="padding:38px 34px;">

                            <div style="
                                font-size:20px;
                                font-weight:800;
                                color:#111827;
                                margin-bottom:14px;
                            ">
                                {{$subject}}
                            </div>

                            <div style="
                                font-size:14px;
                                color:#4b5563;
                                line-height:26px;
                            ">
                                尊敬的用户您好！<br><br>

                                {!! nl2br($content) !!}
                            </div>

                            <!-- 官网信息 -->
                            <div style="
                                margin-top:28px;
                                background:#f8fafc;
                                border-radius:14px;
                                padding:18px 20px;
                                color:#667085;
                                font-size:13px;
                                line-height:26px;
                            ">
                                官网地址：
                                <span style="color:#3B4F8C;font-weight:700;">
                                    {{$url}}
                                </span>
                                <br>
                                推荐使用夸克浏览器打开
                            </div>

                        </td>
                    </tr>

                    <!-- 底部 -->
                    <tr>
                        <td style="
                            padding:22px 34px;
                            border-top:1px solid #eef2f6;
                            background:#fcfcfd;
                            font-size:12px;
                            color:#98a2b3;
                            line-height:20px;
                        ">
                            此邮件由系统自动发送，请勿回复。<br>
                            © {{date('Y')}} {{$name}}
                        </td>
                    </tr>

                </table>

            </td>
        </tr>
    </table>

</div>