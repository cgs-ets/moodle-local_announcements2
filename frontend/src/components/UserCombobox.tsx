import { useState, useMemo } from "react";
import { Group, Avatar, Text, Combobox, useCombobox, PillsInput, Pill, Badge, Flex, CloseButton, Tooltip } from '@mantine/core';
import { IconUser, IconAlertSquare } from '@tabler/icons-react';
import { User } from "../types/types";

type Props = {
  value: User | null;
  onChange: (value: User | null) => void;
  options: User[];
  label: string;
  description?: string;
  tip?: string;
  readOnly?: boolean;
  placeholder?: string;
}

export function UserCombobox({ 
  value, 
  onChange, 
  options, 
  label, 
  description, 
  tip, 
  readOnly = false,
  placeholder = "Search or select..."
}: Props) {
  const [search, setSearch] = useState('');

  const combobox = useCombobox({
    onDropdownClose: () => {
      combobox.resetSelectedOption();
      setSearch('');
    },
    onDropdownOpen: () => combobox.updateSelectedOptionIndex('active'),
  });

  // Decorate user for display
  const decorateUser = (item: User) => ({
    value: item,
    label: `${item.fn} ${item.ln}`,
    username: item.un,
    image: '/local/activities/avatar.php?username=' + item.un
  });

  // Filter options based on search query
  const filteredOptions = useMemo(() => {
    if (!search.trim()) {
      return options;
    }
    const query = search.toLowerCase();
    return options.filter((user) => {
      const fullName = `${user.fn} ${user.ln}`.toLowerCase();
      const username = user.un.toLowerCase();
      return fullName.includes(query) || username.includes(query);
    });
  }, [options, search]);

  const handleValueSelect = (val: User) => {
    setSearch('');
    onChange(val);
    combobox.closeDropdown();
  };

  const handleValueRemove = () => {
    onChange(null);
  };

  // The dropdown options
  const dropdownOptions = filteredOptions.map((item) => {
    const decorated = decorateUser(item);
    return (
      <Combobox.Option value={JSON.stringify(item)} key={item.un}>
        <Group gap="sm">
          <Avatar alt={decorated.label} size={24} mr={5} src={decorated.image} radius="xl">
            <IconUser />
          </Avatar>
          <Text>{decorated.label} ({decorated.username})</Text>
        </Group>
      </Combobox.Option>
    );
  });

  // The selected value badge
  const selectedValue = value ? (() => {
    const decorated = decorateUser(value);
    return (
      <Badge 
        key={decorated.username} 
        variant='filled' 
        p={0} 
        color="gray.2" 
        size="lg" 
        radius="xl" 
        leftSection={
          <Avatar alt={decorated.label} size={24} mr={5} src={decorated.image} radius="xl">
            <IconUser />
          </Avatar>
        }
      >
        <Flex gap={4}>
          <Text className="normal-case font-normal text-black text-sm">{decorated.label}</Text>
          {!readOnly && (
            <CloseButton
              onMouseDown={(e) => {
                e.preventDefault();
                handleValueRemove();
              }}
              variant="transparent"
              size={22}
              iconSize={14}
              tabIndex={-1}
            />
          )}
        </Flex>
      </Badge>
    );
  })() : null;

  const dropdown = (
    <Combobox 
      store={combobox} 
      onOptionSubmit={(optionValue: string) => {
        handleValueSelect(JSON.parse(optionValue));
      }}
      withinPortal={false}
    >
      <Combobox.DropdownTarget>
        <PillsInput 
          pointer 
        >
          <Pill.Group>
            {selectedValue}
            <Combobox.EventsTarget>
              <PillsInput.Field
                onFocus={() => combobox.openDropdown()}
                onClick={() => combobox.openDropdown()}
                onBlur={() => combobox.closeDropdown()}
                value={search}
                placeholder={placeholder}
                onChange={(event) => {
                  setSearch(event.currentTarget.value);
                  combobox.updateSelectedOptionIndex();
                  combobox.openDropdown();
                }}
                onKeyDown={(event) => {
                  if (event.key === 'Backspace' && search.length === 0 && value) {
                    event.preventDefault();
                    handleValueRemove();
                  }
                }}
                className={value ? "hidden" : ""}
              />
            </Combobox.EventsTarget>
          </Pill.Group>
        </PillsInput>
      </Combobox.DropdownTarget>

      <Combobox.Dropdown>
        <Combobox.Options>
          {dropdownOptions.length > 0 
            ? dropdownOptions 
            : <Combobox.Empty>Nothing found...</Combobox.Empty>
          }
        </Combobox.Options>
      </Combobox.Dropdown>
    </Combobox>
  );

  const readOnlyValue = value ? (() => {
    const decorated = decorateUser(value);
    return (
      <Badge 
        key={decorated.username} 
        variant='filled' 
        pl={0} 
        color="gray.2" 
        size="lg" 
        radius="xl" 
        leftSection={
          <Avatar alt={decorated.label} size={24} mr={5} src={decorated.image} radius="xl">
            <IconUser />
          </Avatar>
        }
      >
        <Flex gap={4}>
          <Text className="normal-case font-normal text-black text-sm">{decorated.label}</Text>
        </Flex>
      </Badge>
    );
  })() : <div className="ml-2 italic">No user selected</div>;

  return (
    <div>
      <div className="flex items-center gap-2">
        <Text fz="sm" fw={500} c="#212529">{label}</Text>
        {tip && 
          <Tooltip label={tip} multiline w={320} withArrow>
            <div className="flex items-center gap-1 text-blue-600">
              <IconAlertSquare className="size-5" />
              Important info
            </div>
          </Tooltip>
        }
      </div>
      {description && <Text fz="xs" fw={400} c="#212529">{description}</Text>}
      <div className="mt-1">
        {readOnly ? readOnlyValue : dropdown}
      </div>
    </div>
  );
}

