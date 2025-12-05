import { useEffect, useState } from "react";
import { Tabs, Checkbox, Button, Badge, Group, Text, Stack, Collapse, Flex, CloseButton, ActionIcon } from '@mantine/core';
import { IconAmpersand, IconChevronDown, IconChevronRight, IconCode, IconEye, IconEyeCheck, IconLayersUnion, IconLetterCaseToggle, IconPlus, IconSquareToggle, IconUserPlus, IconUserQuestion, IconUserScan } from '@tabler/icons-react';
import { Audience, User } from "../types/types";
import { UserSelector } from "./UserSelector";

type AudienceOptions = {
  [key: string]: Audience;
};

type Props = {
  options: AudienceOptions | Audience[];
  onChange: (value: Audience[]) => void;
  value: Audience[];  
}

export function AudienceSelector({ 
  options,
  value,
  onChange,
}: Props) {
  // Normalize options to object format
  const normalizedOptions: AudienceOptions = Array.isArray(options)
    ? options.reduce((acc, opt) => {
        acc[opt.id] = {
          id: opt.id,
          label: opt.label,
          roles: opt.roles,
          items: opt.items?.map(item => ({
            id: item.id || '',
            label: item.label,
            children: item.children?.map(grandchild => ({
              id: grandchild.id || '',
              label: grandchild.label,
            })) ?? []
          })) ?? []
        };
        return acc;
      }, {} as AudienceOptions)
    : options;

  const [showCodes, setShowCodes] = useState(false);
  const [activeTab, setActiveTab] = useState<string | null>(Object.keys(normalizedOptions)[0] || null);
  const [selectedChildren, setSelectedChildren] = useState<Set<string>>(new Set());
  const [selectedRoles, setSelectedRoles] = useState<Set<string>>(new Set());
  const [selectedUsers, setSelectedUsers] = useState<User[]>([]);
  const [expandedChildren, setExpandedChildren] = useState<Set<string>>(() => {
    // Auto-expand groups parents that have children
    const expanded = new Set<string>();
    if (normalizedOptions.groups) {
      normalizedOptions.groups.items?.forEach(item => {
        if (item.children && item.children.length > 0) {
          expanded.add(`groups::${item.id}`);
        }
      });
    }
    return expanded;
  });

  const handleChildToggle = (childId: string) => {
    const newSet = new Set(selectedChildren);
    if (newSet.has(childId)) {
      newSet.delete(childId);
    } else {
      newSet.add(childId);
    }
    setSelectedChildren(newSet);
  };

  const handleRoleToggle = (role: string) => {
    const newSet = new Set(selectedRoles);
    if (newSet.has(role)) {
      newSet.delete(role);
    } else {
      newSet.add(role);
    }
    setSelectedRoles(newSet);
  };

  const handleExpandToggle = (childId: string) => {
    const newSet = new Set(expandedChildren);
    if (newSet.has(childId)) {
      newSet.delete(childId);
    } else {
      newSet.add(childId);
    }
    setExpandedChildren(newSet);
  };

  const handleAdd = () => {
    // For users tab, check if users are selected. For other tabs, check if children are selected.
    const hasSelections = activeTab === 'users' 
      ? selectedUsers.length > 0 
      : selectedChildren.size > 0;
    
    if (!activeTab || !hasSelections || (!!normalizedOptions[activeTab].roles?.length && selectedRoles.size === 0)) {
      return;
    }

    const category = normalizedOptions[activeTab];
    
    // Helper to find child by unique path
    // Path format: category::parentId::childId (for grandchildren) or category::childId (for direct children)
    const findChildByPath = (path: string): { child: Audience; isGrandchild: boolean; parentLabel?: string } | null => {
      const parts = path.split('::');
      if (parts.length < 2) return null;
      
      // First part is category name, remove it
      const idParts = parts.slice(1);
      
      // If path has 1 part after category (category::id), it's a direct child
      // If path has 2+ parts (category::parentId::grandchildId), it's a grandchild
      if (idParts.length === 1) {
        // Direct child
        const child = category.items?.find(c => String(c.id) === idParts[0]);
        return child ? { child, isGrandchild: false } : null;
      } else {
        // Grandchild - find parent first
        const parentId = idParts[0];
        const grandchildId = idParts.slice(1).join('::'); // Rejoin in case grandchild ID has ::
        const parent = category.items?.find(c => String(c.id) === parentId);
        if (!parent || !parent.children || parent.children.length === 0) return null;
        const grandchild = parent.children.find(gc => String(gc.id) === grandchildId);
        if (!grandchild) return null;
        // Create a proper Audience object from the grandchild with parent label
        return { 
          child: {
            id: String(grandchild.id),
            label: `${parent.label} - ${grandchild.label}`,
          }, 
          isGrandchild: true,
          parentLabel: parent.label
        };
      }
    };

    const selectedAudienceChildren: Audience[] = [];
    
    // If this is the users tab, convert selected users to audience items
    if (activeTab === 'users') {
      selectedUsers.forEach(user => {
        selectedAudienceChildren.push({
          id: user.un,
          label: `${user.fn} ${user.ln}`,
        });
      });
    } else {
      // Group selections by parent to handle structure correctly
      const processedParents = new Set<string>();
      
      Array.from(selectedChildren).forEach(path => {
        const result = findChildByPath(path);
        if (!result) return;
        
        const { child, isGrandchild } = result;
        
        if (isGrandchild) {
          // For grandchildren, add them directly as items (not nested under parent)
          // Only add if we haven't already processed this grandchild
          const grandchildId = String(child.id);
          if (!processedParents.has(grandchildId)) {
            selectedAudienceChildren.push({
              id: grandchildId,
              label: child.label,
            });
            processedParents.add(grandchildId);
          }
        } else {
          // Direct child (no grandchildren selected, or parent selected)
          if (!processedParents.has(String(child.id))) {
            selectedAudienceChildren.push({
              id: String(child.id),
              label: child.label,
            });
            processedParents.add(String(child.id));
          }
        }
      });
    }

    const newAudience: Audience = {
      id: activeTab,
      label: category.label,
      roles: Array.from(selectedRoles),
      items: selectedAudienceChildren
    };

    onChange([...value, newAudience]);
    
    // Reset selections
    setSelectedChildren(new Set());
    setSelectedRoles(new Set());
    setSelectedUsers([]);
  };

  const handleRemoveAudience = (index: number) => {
    const newValue = [...value];
    newValue.splice(index, 1);
    onChange(newValue);
  };

  useEffect(() => {
    console.log(value);
  }, [value]);

  const renderChild = (item: Audience, categoryName: string, parentPath: string = '') => {
    // Use :: as separator to handle IDs that contain hyphens
    const uniqueId = parentPath ? `${parentPath}::${item.id}` : `${categoryName}::${item.id}`;
    const hasGrandchildren = item.children && item.children.length > 0;
    const isExpanded = expandedChildren.has(uniqueId);
    const isSelected = selectedChildren.has(uniqueId);
    const isParentWithChildren = hasGrandchildren ;

    return (
      <div key={uniqueId}>
        <Flex align="center" gap="xs" py={4}>
          {hasGrandchildren && (
            <Button
              variant="transparent"
              size="compact-sm"
              p={0}
              onClick={() => handleExpandToggle(uniqueId)}
              leftSection={isExpanded ? <IconChevronDown size={16} /> : <IconChevronRight size={16} />}
              c="dark"
            >
              <Text fz="sm" fw={500}>
                {item.label} {showCodes && `(${uniqueId})`}
              </Text>
            </Button>
          )}
          {!isParentWithChildren && (
            <div>
              <Checkbox
                checked={isSelected}
                onChange={() => handleChildToggle(uniqueId)}
                label={item.label + (showCodes ? ` (${uniqueId})` : '')}
              />
            </div>
          )}
        </Flex>
        {hasGrandchildren && (
          <Collapse in={isExpanded} className="ml-8">
            <div>
              {item.children?.map(child => renderChild(child, categoryName, uniqueId))}
            </div>
          </Collapse>
        )}
      </div>
    );
  };

  // Helper function to get all selected item labels from an audience
  const getAllSelectedItems = (audience: Audience): string[] => {
    const selected: string[] = [];
    
    const traverse = (items: Audience[]) => {
      items.forEach(item => {
        // Only add items that don't have children (leaf nodes) or if they're direct selections
        if (!item.children || item.children.length === 0) {
          selected.push(item.label + (showCodes ? ` (${item.id})` : ''));
        } else {
          // If it has children, traverse them
          traverse(item.children ?? []);
        }
      });
    };
    
    traverse(audience.items ?? []);
    return selected;
  };

  return (
    <div>

      {/* Display selected audiences at the top */}
      {value.length > 0 && (
        <div>
          <div className="flex items-flex-end pl-[6px] -mb-[1px]">
            {value.map((audience, index) => {
              const selectedItems = getAllSelectedItems(audience);
              return (
                <>
                  <div
                    key={index}
                    className="border rounded-sm py-3 pl-4 pr-6 bg-orange-50 transform skew-x-[-6deg] -ml-[1px] max-w-[300px] overflow-hidden"
                  >
                    <CloseButton
                      onMouseDown={(e) => {
                        e.preventDefault();
                        handleRemoveAudience(index);
                      }}
                      size={20}
                      iconSize={14}
                      tabIndex={-1}
                      className="absolute top-2 right-2"
                    />
                    <Stack gap="5">
                      <div>
                        <Text fz="sm" fw={600}>
                          {audience.label}
                        </Text>
                      </div>
                      <div>
                        <Group gap="5">
                          {selectedItems.map((item, itemIndex) => (
                            <Badge
                              key={itemIndex}
                              variant="light"
                              color="blue"
                              size="sm"
                            >
                              <Text 
                                fz="xs" 
                                className="normal-case text-black truncate"
                                title={item}
                              >
                                {item}
                              </Text>
                            </Badge>
                          ))}
                        </Group>
                      </div>
                      <div>
                        <Group gap="5" className="mt-1">
                          {audience.roles?.map((role, roleIndex) => (
                            <Badge
                              key={roleIndex}
                              variant="light"
                              color="gray"
                              size="xs"
                            >
                              <Text fz="xs" className="normal-case text-black">
                                {role.charAt(0).toUpperCase() + role.slice(1)}
                              </Text>
                            </Badge>
                          ))}
                        </Group>
                      </div>
                    </Stack>
                  </div>
                </>
              );
            })}
            <Button className="ml-6" variant="light" size="compact-sm" onClick={() => {}} leftSection={<IconUserQuestion size={16} />}>Preview users</Button>
          </div>
        </div>
      )}

      <div className="border bg-white">

        {/* Tabs for categories */}
        {Object.keys(normalizedOptions).length > 0 && (
          <Tabs value={activeTab} onChange={(val) => {
            setActiveTab(val);
            setSelectedChildren(new Set());
            setSelectedRoles(new Set());
            setSelectedUsers([]);
            
            // Auto-expand groups parents when switching to groups tab
            if (val === 'groups' && normalizedOptions.groups) {
              const newExpanded = new Set(expandedChildren);
              normalizedOptions.groups.items?.forEach(item => {
                if (item.children && item.children.length > 0) {
                  newExpanded.add(`groups::${item.id}`);
                }
              });
              setExpandedChildren(newExpanded);
            }
          }}>
            <Tabs.List>
              {Object.entries(normalizedOptions).map(([key, option]) => (
                <Tabs.Tab key={key} value={key} className="py-3 px-4">
                  {option.label}
                </Tabs.Tab>
              ))}
              <div className="absolute right-0 top-0 opacity-0">
                <ActionIcon 
                  className="cursor-default"
                  variant="light" 
                  size="compact-sm" 
                  onClick={() => setShowCodes(!showCodes)}>
                    <IconCode size={16} />
                </ActionIcon>
              </div>
              
            </Tabs.List>

            {Object.entries(normalizedOptions).map(([key, option]) => (
              <Tabs.Panel key={key} value={key} className="p-4">
                <Stack gap="md">

                  {/* If this is the users tab, add the UserSelector component */}
                  {key === 'users' && (
                    <UserSelector
                      users={selectedUsers}
                      setUsers={setSelectedUsers}
                      label="Users"
                      multiple={true}
                      readOnly={false}
                    />
                  )}

                  {/* Children checkboxes */}
                  {!!option.items?.length && option.items.length > 0 ? (
                    <div>
                      {option.items.map(child => renderChild(child, key))}
                    </div>
                  ) : null }

                  {/* Roles checkboxes */}
                  {!!option.roles?.length && option.roles.length > 0 && (
                    <div>
                      <Text fz="sm" fw={500} mb="xs">Roles</Text>
                      <Stack gap="5">
                        {option.roles.map(role => (
                          <Checkbox
                            key={role}
                            checked={selectedRoles.has(role)}
                            onChange={() => handleRoleToggle(role)}
                            label={role.charAt(0).toUpperCase() + role.slice(1)}
                          />
                        ))}
                      </Stack>
                    </div>
                  )}

                  {/* Add button */}
                  <div>
                    <Button
                      onClick={handleAdd}
                      disabled={
                        (key === 'users' ? selectedUsers.length === 0 : selectedChildren.size === 0) || 
                        (!!option.roles?.length && selectedRoles.size === 0)
                      }
                      variant="filled"
                      leftSection={<IconUserPlus size={16} />}
                      size="compact-md"
                    >
                      Add Audience
                    </Button>
                  </div>
                </Stack>
              </Tabs.Panel>
            ))}
          </Tabs>
        )}
      </div>

    </div>
  );
}
