{!! Html::script(env("APP_URL").'/assets/backend/libs/pop.js?v=20180807') !!}
<script type="text/html" id="template">
    <div class="category-wrap">
        <input data-id="{#id#}" data-parent="{#parent_id#}"  data-name="{#value#}" data-level="{#level#}"
               data-uniqueId="categoryIds_{#id#}" class="category_checks" type="checkbox"/>
        <input class="btn btn-outline btn-primary category-btn" type="button" value="{#value#}"/>
    </div>
</script>

<script>

    $("body .td_c").on("change", ".type-s", function () {
        var that = $(this);
        var val = that.find("option:selected").val();

        var category_checked = [];
        var category_ids = [];
        // 初始化
        function initCategory() {
            category_checked = [];
            category_ids = [];
            var data = {
                parentId:val,
                "type-select-category-button": true,
                _token: _token
            };
            $.get('{{route('admin.industry.get_classification')}}', data, function (html) {
                $('#category-box').children().remove();
                $('#category-box').append(html);
                $('#category-box').find("input").iCheck({
                    checkboxClass: 'icheckbox_square-green',
                    radioClass: 'iradio_square-green',
                    increaseArea: '20%'
                });
            });
        }

        @if(!isset($goods_info))
        initCategory();
        @endif

        $("#hidden-category-id input").each(function () {
            category_ids.push(parseInt($(this).val()));
        });
        category_checked = $(".category_name").text().split("/");

        initTheOrderCheckedCats();

        function moveTheOrderCat($parentObject, template) {
            if ($parentObject.length == 1) {
                $parentObject.children('ul').append(template);
            } else {
                $(".category_name").children('ul').append(template);
            }
        }

        function initTheOrderCheckedCats() {
            $(".category_name li").each(function () {
                var parentId = $(this).data('parent');
                var $parentObject = $(".category_name").find('[data-id=' + parentId + ']');
                moveTheOrderCat($parentObject, $(this));
            });
        }

        function addTheOrderCheckedCat(dataId, dataParentId, dataName, dataCode='') {

            var whetherExistNode = $(".category_name").find('[data-id=' + dataId + ']').length;
            if (0 == whetherExistNode) {
                var template = " <li data-id=" + dataId + " data-parent=" + dataParentId + "><span>" + dataCode + " " + dataName +
                    "</span><ul></ul>" +
                    " </li>";
                var $parentObject = $(".category_name").find('[data-id=' + dataParentId + ']');
                moveTheOrderCat($parentObject, template);
            }
        }
        // 移除已经选中的二级与三级选项
        function removeTheOrderCheckedCat(dataId) {
            var $node = $(".category_name").find('[data-id=' + dataId + ']');
            var $childrenNode = $node.children('ul').children();
            if ($childrenNode.length > 0) {
                var $nodeParent = $node.parents('li').first();
                // moveTheOrderCat($nodeParent, $childrenNode);
                $nodeParent.find('ul').html("");
            }
            $node.remove();
        }

        function operator($object, parentId, parentName, level, flag) {
            // $flag =1 表示checked操作， $flag=2 表示unchecked操作， $flag=3表示点击钮
            // $object 表示 category-content类对象

            // 首先 写unchecked操作
            if (flag == 2) {
                // 在category_ids里面找parentId
                var positionIndex = category_ids.indexOf(parentId);
                category_ids.splice(positionIndex, 1);

                // 同上， 将parentName从category_checked中移除
                positionIndex = category_checked.indexOf(parentName);
                category_checked.splice(positionIndex, 1);

                //将表单中的hidden 某个category_id移除
                $("#hidden-category-id").find("#category_" + parentId).remove();
            } else {
                // html
                var html = "";

                if (level == 3) {
                    //3级分类直接处理
                    handle($object, parentId, parentName, flag, html);
                    return;
                }

                // 1级2级分类 在flag =1 或者 flag=3时 一定会向后台请求数据
                //var groupId = $("select[name=category_group]").children('option:selected').val();
                var data = {
                    "parentId": parentId,
                    //"groupId": groupId,
                    "type-click-category-button": true
                };
                outID = parentId;
                $.get(
                    "{{route('admin.industry.get_classification')}}", data,
                    function (json) {
                        for (var i = 0; i < json.length; i++) {

                            var data = {
                                id: json[i].id,
                                value: json[i].classification_name,
                                parent_id: json[i].parent_id,
                                classification_code:json[i].classification_code,
                                level:json[i].level,
                            }
                            html = html + $.convertTemplate('#template', data, '');
                        }

                        handle($object, parentId, parentName, flag, html);
                    });
            }
        }

        function handle($object, parentId, parentName, flag, html) {
            // 异步请求后， 模板数据全都存在于var html中 下一步获得 类为 category-content的位置 这里有个bug,  应该要放进 ajax里面
            var categoryContentPosition = $object.data('position');

            if (categoryContentPosition != "right") {
                // categoryContentPosition 不等于 right 找到它的next sibling
                var $nextObject = $object.next();
                // 首先将 $nextObject里面的内容清空
                $nextObject.children().remove();
                $nextObject.append(html);
                // debugger;
                $(".category_checks").iCheck({checkboxClass: 'icheckbox_square-green'});
                //将id存在于 category_ids里的 checkbox checked
                for (var i = 0; i < category_ids.length; i++) {
                    $("input[data-uniqueId=categoryIds_" + category_ids[i] + "]").iCheck('check');
                }
            }
            if (1 == flag) {
                parentId = parseInt(parentId);
                if (category_ids.indexOf(parentId) < 0) {
                    category_ids.push(parentId);
                    category_checked.push(parentName);
                    $("#hidden-category-id").append("<input  type=\"hidden\" name=\"category_id[]\" id=category_" + parentId + " value=" + parentId + ">");
                }
            }

        }

        $('body').on('click', '.category-btn', function () {
            // 获得相邻的checkbox
            var $checkbox = $(this).prev().find(':checkbox');
            var id = $checkbox.data('id');
            var name = $checkbox.data('name');
            var level = $checkbox.data('level');
            var $parentCategoryContent = $checkbox.closest('.category-content');
            operator($parentCategoryContent, id, name, level, 3);
        });
        var outID = ""; // 记录请求后台的二级标题id 
        // 点击复选框 
        $('body').on('ifChanged', '.category_checks', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');
            var parentId = $(this).data('parent');
            var level = $(this).data('level');
            var code = $(this).data('code');
            var $parentCategoryContent = $(this).closest('.category-content');
            console.log("$(this).is(':checked')", $(this).is(':checked'));
            if ($(this).is(':checked')) {
                operator($parentCategoryContent, id, name, level, 1);
                addTheOrderCheckedCat(id, parentId, name, code);
            } else {
                // 点击右边的复选框
                operator($parentCategoryContent, id, name, level, 2); 
                removeTheOrderCheckedCat(id);
                console.log('level === 2', parseInt(level) ===2);
                 if (parseInt(level) ===2 ){
                    // 判断当前选中的二级标题 跟 最后一次发送后台请求的二级标题 是否一样
                    if (id == outID ) {
                         // 移除右边已经选中的所有复选框 
                        $('.titCon02 .category-wrap .icheckbox_square-green').each(function(item,i) {
                            if ($(this).is(".checked")) {
                                $(this).removeClass("checked");
                                var rightID  = $(this).find("input").data('id');
                                var rightName  = $(this).find("input").data('name');
                                // 在category_ids里面找parentId
                                var positionIndex = category_ids.indexOf(rightID);
                                console.log('移除positionIndex',positionIndex);
                                category_ids.splice(positionIndex, 1);

                                // 同上， 将parentName从category_checked中移除
                                positionIndex = category_checked.indexOf(rightName);
                                category_checked.splice(positionIndex, 1);
                                //将表单中的hidden 某个category_id移除
                                $("#hidden-category-id").find("#category_" + rightID).remove();
                            }
                        }) 
 
                    }
                   
                    console.log('level =2, category_ids', category_ids);
                 } 
                // 右边的复选项取消选中
                else{
                    // 在category_ids里面找parentId
                    console.log('category_ids', category_ids,"id", id);
                    // var rigIndex = category_ids.indexOf(id);
                    // console.log('移除positionIndex',rigIndex);
                    // category_ids.splice(rigIndex, 1);

                    // 同上， 将parentName从category_checked中移除
                    // rigIndex = category_checked.indexOf(name);
                    // category_checked.splice(rigIndex, 1);
                    //将表单中的hidden 某个category_id移除
                    $("#hidden-category-id").find("#category_" + id).remove();
                }
            }
            console.log('category_ids', category_ids);
        });
    });
</script>

<script>
    $('#spec-value-form').ajaxForm({
        success: function (result) {
            if (result.status) {
                swal({
                    title: "保存成功！",
                    text: "",
                    type: "success"
                }, function () {
                    location.reload();
                });
            } else {
                swal(result.message, '', 'error');
            }

        }
    });


    //根据显示类型返回格式
    function getTr() {
        //数据
        var specRow = '<tr class="td_c"><td>' + '<select class="form-control"  name="top_nice_classification_id">'
            + '<option value="">请选择</option>' + @foreach($classifications as $item)' + <option value="{{$item->id}}">{{$item->classification_code. '-' .$item->classification_name}}</option>' +@endforeach' + </select>'
                +'<td><input type="text" class="form-control" name="alias" />' +
                '</td>' +
                        '<td><a href="javascript:;" class="btn btn-xs btn-primary operatorPhy">' +
                '<i class="fa fa-trash" data-toggle="tooltip" data-placement="top" data-original-title="删除"></i></a></td></tr>';

        return specRow;
    }

    //预定义

    $('#spec_box tr').each(
            function (i) {
                initButton(i);
            }
    );


    //添加规格按钮(点击绑定)
    $('#specAddButton').click(
            function () {

                var specRow = getTr();

                $('#spec_box').append(specRow);

            initButton(specSize);
            }
    );

    //按钮(点击绑定)
    function initButton(indexValue) {
        //功能操作按钮
        $('#spec_box tr:eq(' + indexValue + ') .operator').click(function () {
            var obj = $(this);
            swal({
                title: "确定删除该规格值吗?",
                text: "",
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: "删除",
                closeOnConfirm: false
            }, function () {
                var url = obj.data('url');
                var data = {
                    id: obj.data('id'),
                    _token: _token
                };

                $.post(url, data, function (ret) {
                    if (ret.status) {
                        obj.parent().parent().remove();
                        swal("删除成功!", "", "success");
                    } else {
                        swal("改规格值下面存在商品，不能删除!", "", "warning");
                    }
                });

            });
        });

        $('#spec_box tr:eq(' + indexValue + ') .operatorPhy').click(function () {
            $(this).parent().parent().remove();
        });
    }
</script>
